<?php

use App\Console\Commands\FingerprintKey;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\User;
use App\Support\Keys\KeyFingerprints;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use ParagonIE\CipherSweet\CipherSweet;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 8A.3: key fingerprints, start-up verification, APP_KEY and
| CIPHERSWEET_KEY rotation.
*/

beforeEach(function () {
    // Maintenance mode in an in-memory store, never the shared file.
    config(['app.maintenance.driver' => 'cache', 'app.maintenance.store' => 'array']);
    $this->ciphersweetKey = (string) config('ciphersweet.providers.string.key');
    $this->appKey = (string) config('app.key');
});

afterEach(function () {
    useCipherSweetKey($this->ciphersweetKey);
    useAppKey($this->appKey, []);
});

function useCipherSweetKey(string $key): void
{
    config(['ciphersweet.providers.string.key' => $key]);
    app()->forgetInstance(CipherSweet::class);
    Person::$cipherSweetEncryptedRow = null;
    OrganizationContact::$cipherSweetEncryptedRow = null;
}

/** @param list<string> $previous */
function useAppKey(string $key, array $previous): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previous]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

function keyFile(string $value): string
{
    $path = tempnam(sys_get_temp_dir(), 'key');
    file_put_contents($path, $value."\n");

    return $path;
}

function decrypts(string $payload): bool
{
    try {
        Crypt::decrypt($payload);

        return true;
    } catch (DecryptException) {
        return false;
    }
}

test('the first verification records both fingerprints; later runs match; a different key is refused', function () {
    expect(KeyFingerprints::verify())->toBe(['app_key' => ['ok' => true, 'state' => 'registered'], 'ciphersweet_key' => ['ok' => true, 'state' => 'registered']])
        ->and(KeyFingerprints::verify()['ciphersweet_key'])->toBe(['ok' => true, 'state' => 'active']);

    $stored = DB::table('key_fingerprints')->get();
    expect($stored)->toHaveCount(2)
        ->and($stored->pluck('fingerprint')->all())->not->toContain($this->ciphersweetKey)
        ->and(json_encode($stored))->not->toContain($this->ciphersweetKey);

    // A restore with another installation's key: refused.
    useCipherSweetKey(bin2hex(random_bytes(32)));
    expect(KeyFingerprints::verify()['ciphersweet_key'])->toBe(['ok' => false, 'state' => 'the running key does not match the active fingerprint'])
        ->and(Artisan::call('buffalo:keys:verify'))->toBe(1)
        ->and(Artisan::output())->toContain('MISMATCH')->not->toContain((string) config('ciphersweet.providers.string.key'));
});

test('in production, requests are refused while the keys do not match', function () {
    KeyFingerprints::verify();
    app()->detectEnvironment(fn () => 'production');

    $this->get('/login')->assertOk();

    useCipherSweetKey(bin2hex(random_bytes(32)));
    $this->get('/login')->assertStatus(503);
});

test('a key read from standard input is fingerprinted and recognised, never printed', function () {
    KeyFingerprints::verify();
    $command = app(FingerprintKey::class);
    $command->setLaravel(app());

    $run = function (string $key) use ($command): array {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $key."\n");
        rewind($stream);
        $input = new ArrayInput(['key' => 'ciphersweet_key'], $command->getDefinition());
        $input->setStream($stream);
        $output = new BufferedOutput;

        return [$command->run($input, $output), $output->fetch()];
    };

    [$exit, $output] = $run($this->ciphersweetKey);
    expect($exit)->toBe(0)
        ->and($output)->toContain(KeyFingerprints::fingerprint('ciphersweet_key', $this->ciphersweetKey))
        ->and($output)->toContain('This is the active key.')
        ->and($output)->not->toContain($this->ciphersweetKey);

    [$exit, $output] = $run(bin2hex(random_bytes(32)));
    expect($exit)->toBe(1)->and($output)->toContain('not known to this database');
});

test('APP_KEY rotation: old values stay readable through previous keys, then are re-encrypted', function () {
    KeyFingerprints::verify();
    $old = $this->appKey;
    $payload = Crypt::encrypt('signed and sealed');
    $user = User::factory()->withTwoFactor()->create();
    $secret = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

    // 1. Prepare: a new key in a 0600 file, recorded as pending.
    $file = sys_get_temp_dir().'/app_key.'.bin2hex(random_bytes(4));
    expect(Artisan::call('buffalo:keys:rotate-app-key', ['--prepare' => true, '--output' => $file]))->toBe(0)
        ->and(substr(sprintf('%o', fileperms($file)), -4))->toBe('0600');
    $new = trim((string) file_get_contents($file));
    unlink($file);
    expect(Artisan::output())->not->toContain($new)
        ->and(KeyFingerprints::stored('app_key')['pending'])->toBe(KeyFingerprints::fingerprint('app_key', $new));

    // Restarting with the new key but without the old one as a previous key is refused.
    useAppKey($new, []);
    expect(KeyFingerprints::verify()['app_key']['ok'])->toBeFalse()
        ->and(decrypts($payload))->toBeFalse();

    // 2. Restart with APP_PREVIOUS_KEYS: old values are readable; the rotation completes.
    useAppKey($new, [$old]);
    expect(decrypts($payload))->toBeTrue()
        ->and(Crypt::decrypt($payload))->toBe('signed and sealed')
        ->and(KeyFingerprints::verify()['app_key'])->toBe(['ok' => true, 'state' => 'promoted'])
        ->and(KeyFingerprints::stored('app_key'))->toBe(['active' => KeyFingerprints::fingerprint('app_key', $new), 'pending' => null]);

    // 3. Re-encrypt: two-factor secrets no longer need the old key.
    expect(Artisan::call('buffalo:keys:rotate-app-key', ['--reencrypt' => true]))->toBe(0);
    useAppKey($new, []);
    $rotated = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
    expect($rotated)->not->toBe($secret)
        ->and(decrypts($rotated))->toBeTrue()
        ->and(Crypt::decrypt($rotated))->toBe(decrypt_with($old, $secret));
});

/** Decrypts a payload with a specific key. */
function decrypt_with(string $key, string $payload): mixed
{
    return (new Encrypter(base64_decode(substr($key, 7)), 'AES-256-CBC'))->decrypt($payload);
}

test('CIPHERSWEET_KEY rotation re-encrypts every field and blind index, resumably', function () {
    CrossFixtures::system();
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    $people = collect(range(1, 7))->map(fn (int $i) => CrossFixtures::person($lga, [
        'first_name' => "Rotation{$i}", 'nin' => sprintf('2%010d', $i), 'phone' => sprintf('0803555%04d', $i),
        'date_of_birth' => sprintf('1980-01-%02d', $i), 'address' => "{$i} Marina Road, Lagos",
    ]));
    $empty = CrossFixtures::person($lga, ['first_name' => 'NoProtectedFields', 'nin' => null, 'phone' => null, 'date_of_birth' => null, 'address' => null]);
    $deleted = CrossFixtures::person($lga, ['first_name' => 'Deleted', 'nin' => '29999999999']);
    $deleted->delete();
    $site = OrganizationSite::factory()->of(Organization::factory()->create())->in($lga)->create();
    $contact = new OrganizationContact;
    $contact->forceFill(['name' => 'Rotation Contact', 'position' => 'Manager', 'phone' => '08035550199', 'email' => 'contact@rotation.example', 'organization_site_id' => $site->id, 'state_id' => $lga->state_id, 'lga_id' => $lga->id])->save();
    KeyFingerprints::verify();

    $before = DB::table('persons')->where('id', $people[0]->id)->value('nin');
    $newKey = bin2hex(random_bytes(32));
    $file = keyFile($newKey);

    // Interrupted after one batch of 3, then resumed.
    expect(Artisan::call('buffalo:keys:rotate-ciphersweet', ['--new-key-file' => $file, '--batch' => 3, '--max-batches' => 1]))->toBe(0)
        ->and(Artisan::output())->toContain('Run the command again')
        ->and(app()->isDownForMaintenance())->toBeTrue()
        ->and(DB::table('key_rotations')->value('status'))->toBe('running');
    expect(Artisan::call('buffalo:keys:rotate-ciphersweet', ['--new-key-file' => $file, '--batch' => 3]))->toBe(0)
        ->and(Artisan::output())->not->toContain($newKey)
        ->and(DB::table('key_rotations')->value('status'))->toBe('completed')
        ->and(DB::table('persons')->where('id', $people[0]->id)->value('nin'))->not->toBe($before);

    // Restart with the new key: the start-up check completes the rotation.
    useCipherSweetKey($newKey);
    expect(KeyFingerprints::verify()['ciphersweet_key'])->toBe(['ok' => true, 'state' => 'promoted']);
    Artisan::call('up');

    // Every value decrypts, and every blind index search still matches.
    foreach ($people as $i => $person) {
        $n = $i + 1;
        $fresh = Person::query()->withoutGlobalScopes()->findOrFail($person->id);
        expect([$fresh->nin, $fresh->phone, $fresh->date_of_birth, $fresh->address])
            ->toBe([sprintf('2%010d', $n), sprintf('+234803555%04d', $n), sprintf('1980-01-%02d', $n), "{$n} Marina Road, Lagos"]);
        expect(Person::query()->withoutGlobalScopes()->whereBlind('nin', 'nin_bidx', sprintf('2%010d', $n))->pluck('id')->all())->toBe([$person->id])
            ->and(Person::query()->withoutGlobalScopes()->whereBlind('phone', 'phone_bidx', sprintf('+234803555%04d', $n))->pluck('id')->all())->toBe([$person->id])
            ->and(Person::query()->withoutGlobalScopes()->whereBlind('date_of_birth', 'dob_bidx', sprintf('1980-01-%02d', $n))->pluck('id')->all())->toBe([$person->id]);
    }
    expect(Person::query()->withoutGlobalScopes()->withTrashed()->whereBlind('nin', 'nin_bidx', '29999999999')->pluck('id')->all())->toBe([$deleted->id])
        ->and(Person::query()->withoutGlobalScopes()->findOrFail($empty->id)->nin)->toBeNull()
        ->and(DB::table('blind_indexes')->where('indexable_id', $empty->id)->where('indexable_type', $empty->getMorphClass())->count())->toBe(0);
    $freshContact = OrganizationContact::query()->withoutGlobalScopes()->findOrFail($contact->id);
    expect([$freshContact->phone, $freshContact->email])->toBe([$contact->phone, $contact->email])
        ->and(OrganizationContact::query()->withoutGlobalScopes()->whereBlind('phone', 'phone_bidx', (string) $contact->phone)->pluck('id')->all())->toBe([$contact->id]);

    // The old key no longer reads the data.
    useCipherSweetKey($this->ciphersweetKey);
    expect(fn () => Person::query()->withoutGlobalScopes()->findOrFail($people[0]->id)->nin)->toThrow(Exception::class);
});

test('a lost key is retired only with confirmation, then its replacement is registered', function () {
    KeyFingerprints::verify();
    useCipherSweetKey(bin2hex(random_bytes(32)));
    expect(KeyFingerprints::verify()['ciphersweet_key']['ok'])->toBeFalse();

    $this->artisan('buffalo:keys:retire', ['key' => 'ciphersweet_key'])
        ->expectsQuestion('Type RETIRE ciphersweet_key to continue', 'yes')
        ->assertExitCode(1);
    expect(KeyFingerprints::verify()['ciphersweet_key']['ok'])->toBeFalse();

    $this->artisan('buffalo:keys:retire', ['key' => 'ciphersweet_key'])
        ->expectsQuestion('Type RETIRE ciphersweet_key to continue', 'RETIRE ciphersweet_key')
        ->assertExitCode(0);
    expect(KeyFingerprints::verify()['ciphersweet_key'])->toBe(['ok' => true, 'state' => 'registered'])
        ->and(DB::table('key_fingerprints')->where('key_name', 'ciphersweet_key')->where('status', 'retired')->count())->toBe(1);
});
