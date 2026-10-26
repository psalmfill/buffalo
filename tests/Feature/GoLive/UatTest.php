<?php

use App\Console\DemoCommands\SeedUatCommand;
use App\Http\Middleware\VerifyKeyFingerprints;
use App\Models\Incident;
use App\Models\Person;
use App\Models\State;
use App\Models\UatFeedback;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Uat\Uat;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Inertia\Testing\AssertableInertia as Assert;

/*
| The UAT environment (Phase 9B.5): it refuses production mode, seeds
| synthetic data and one account per role, shows its banner, and its
| feedback exists only there.
*/

function asUat(?object $test = null): void
{
    app()->detectEnvironment(fn () => 'uat');
    // Laravel skips CSRF checks only in the "testing" environment.
    // UAT is production-like: the key fingerprint check would run against a
    // test database without fingerprints.
    $test?->withoutMiddleware([PreventRequestForgery::class, VerifyKeyFingerprints::class]);
}

afterEach(fn () => app()->detectEnvironment(fn () => 'testing'));

test('the UAT seed refuses outside the UAT environment', function () {
    expect(Artisan::call('buffalo:uat:seed'))->toBe(1)
        ->and(Artisan::output())->toContain('runs only in the UAT environment')
        ->and(DB::table('users')->count())->toBe(0);
});

test('the UAT seed refuses a database with real accounts', function () {
    User::factory()->directorGeneral()->create(['email' => 'dg@agency.gov.ng']);
    asUat();

    expect(Artisan::call('buffalo:uat:seed'))->toBe(1)
        ->and(Artisan::output())->toContain('not a UAT database');
});

test('the UAT seed creates synthetic data, the accounts with 2FA, and prints the credentials once', function () {
    asUat();

    expect(Artisan::call('buffalo:uat:seed', ['--incidents' => 25]))->toBe(0);
    $output = Artisan::output();

    $emails = array_map(fn ($local) => "{$local}@".Uat::DOMAIN, array_keys(SeedUatCommand::ACCOUNTS));
    $users = User::query()->orderBy('id')->get();

    expect($users->pluck('email')->all())->toBe($emails)
        ->and($users->every(fn (User $u) => $u->two_factor_confirmed_at !== null && $u->password_changed_at !== null))->toBeTrue()
        ->and($output)->toContain('TOTP secret', 'sd.lagos@'.Uat::DOMAIN)
        ->and(State::query()->where('is_live', true)->orderBy('code')->pluck('code')->all())->toBe(['KN', 'LA'])
        ->and(DB::table('lgas')->where('name', 'like', 'Demo LGA %')->count())->toBe(24)
        ->and(DB::table('boundary_sources')->where('synthetic', true)->count())->toBe(1);

    app(JurisdictionContext::class)->system();
    expect(Incident::query()->where('title', 'like', '[Demo]%')->count())->toBe(25)
        ->and(Person::query()->where('last_name', 'Testperson')->count())->toBe(1);

    // A password and TOTP secret really work.
    preg_match('/sd\.lagos@'.preg_quote(Uat::DOMAIN).'\s*\|\s*[^|]+\|\s*(\S+)\s*\|\s*(\S+)/', $output, $m);
    $sd = User::query()->where('email', 'sd.lagos@'.Uat::DOMAIN)->firstOrFail();
    expect(Hash::check($m[1], $sd->password))->toBeTrue()
        ->and(decrypt($sd->two_factor_secret))->toBe($m[2]);

    // Each account's TOTP setup URI, for the same secret.
    preg_match('#^\s*sd\.lagos@'.preg_quote(Uat::DOMAIN).'\s+(otpauth://totp/\S+)$#m', $output, $uri);
    parse_str((string) parse_url($uri[1], PHP_URL_QUERY), $query);
    expect($uri[1])->toContain(rawurlencode('sd.lagos@'.Uat::DOMAIN))
        ->and($query['secret'] ?? null)->toBe($m[2])
        ->and(substr_count($output, 'otpauth://totp/'))->toBe(count(SeedUatCommand::ACCOUNTS));

    // A second run does not print anything new.
    expect(Artisan::call('buffalo:uat:seed', ['--if-empty' => true]))->toBe(0)
        ->and(Artisan::output())->not->toContain('TOTP secret')
        ->and(Artisan::call('buffalo:uat:seed'))->toBe(1);

    // New credentials on request only.
    expect(Artisan::call('buffalo:uat:seed', ['--reset-credentials' => true]))->toBe(0)
        ->and(Hash::check($m[1], $sd->fresh()->password))->toBeFalse();
});

test('the UAT start-up check uses the production rules, and production refuses the UAT environment', function () {
    config([
        'app.debug' => false, 'app.url' => 'https://uat.example', 'session.secure' => true, 'session.http_only' => true,
        'session.same_site' => 'lax', 'buffalo.security.csp.report_only' => false, 'inertia.devtools.enabled' => false,
        'buffalo.rollout.enforce' => true, 'session.lifetime' => 30, 'buffalo.edge.published' => 'direct',
    ]);

    config(['app.env' => 'uat']);
    expect(Artisan::call('buffalo:production:check', ['--uat' => true]))->toBe(0)
        ->and(Artisan::call('buffalo:production:check'))->toBe(1)
        ->and(Artisan::output())->toContain('APP_ENV must be production');

    config(['app.env' => 'production', 'app.debug' => true]);
    expect(Artisan::call('buffalo:production:check', ['--uat' => true]))->toBe(1)
        ->and(Artisan::output())->toContain('APP_ENV must be uat', 'APP_DEBUG must be false');
});

test('every page in UAT carries the banner and the feedback button flag; elsewhere neither', function () {
    $user = User::factory()->stateDirector()->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('app.uat', false)->where('app.banner', null));

    asUat($this);
    $this->actingAs($user)->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('app.uat', true)
            ->where('app.banner.text', 'UAT — TEST DATA ONLY')->where('app.banner.level', 'secret'));
});

test('feedback answers 404 outside UAT', function () {
    $user = User::factory()->stateDirector()->create();

    $this->actingAs($user)->post(route('uat.feedback.store'), ['text' => 'Hello there', 'page_url' => '/dashboard'])->assertNotFound();
    expect(UatFeedback::query()->count())->toBe(0);
});

test('in UAT any tester leaves feedback: path only, with their role', function () {
    asUat($this);
    $user = User::factory()->lgaChief()->create();

    $this->actingAs($user)->post(route('uat.feedback.store'), [
        'text' => 'The map took long to load.', 'page_url' => '/incidents/map?state=abc&token=secret',
    ])->assertRedirect();

    $feedback = UatFeedback::query()->sole();
    expect($feedback->page_url)->toBe('/incidents/map')
        ->and($feedback->role)->toBe('lga_security_chief')
        ->and($feedback->user_id)->toBe($user->id);

    $this->actingAs($user)->post(route('uat.feedback.store'), ['text' => 'x', 'page_url' => 'https://evil.example'])
        ->assertSessionHasErrors(['text', 'page_url']);
});

test('only super admins list and export the feedback; the CSV is safe', function () {
    asUat($this);
    $tester = User::factory()->stateDirector()->create(['name' => 'Tester One']);
    $this->actingAs($tester)->post(route('uat.feedback.store'), ['text' => '=HYPERLINK("http://x")', 'page_url' => '/reports']);

    $this->actingAs($tester)->get(route('uat.feedback.index'))->assertForbidden();
});

test('super admins list and export the feedback with formulas neutralised', function () {
    asUat($this);
    $tester = User::factory()->stateDirector()->create(['name' => 'Tester One']);
    $feedback = new UatFeedback(['role' => 'state_director', 'page_url' => '/reports', 'text' => '=HYPERLINK("http://x")']);
    $feedback->forceFill(['user_id' => $tester->id])->save();
    $admin = User::factory()->superAdmin()->create();

    $this->actingAs($admin)->get(route('uat.feedback.index'))
        ->assertInertia(fn (Assert $page) => $page->component('uat/feedback')->has('feedback', 1)->where('feedback.0.user', 'Tester One'));

    $csv = $this->actingAs($admin)->get(route('uat.feedback.export'))->assertOk()->streamedContent();
    expect($csv)->toContain("'=HYPERLINK")->toContain('Tester One');
});

test('the UAT routes and seed command do not exist in a production deployment', function () {
    $run = fn (string $env, array $command) => Process::path(base_path())
        ->env(['APP_ENV' => $env, 'APP_DEBUG' => 'false'])->run(['php', 'artisan', ...$command])->output();

    expect($run('production', ['route:list', '--name=uat', '--json']))->not->toContain('uat.feedback')
        ->and($run('production', ['list', '--raw']))->not->toContain('buffalo:uat:seed')
        ->and($run('uat', ['route:list', '--name=uat', '--json']))->toContain('uat.feedback.store')
        ->and($run('uat', ['list', '--raw']))->toContain('buffalo:uat:seed');
});

test('the frontend never imports helpers of UAT-only routes (production builds have none)', function () {
    $offenders = collect(File::allFiles(resource_path('js')))
        ->filter(fn ($file) => in_array($file->getExtension(), ['ts', 'tsx'], true) && ! str_contains($file->getPathname(), '/actions/') && ! str_contains($file->getPathname(), '/routes/'))
        ->filter(fn ($file) => str_contains($file->getContents(), 'Controllers/Uat/') || str_contains($file->getContents(), '@/routes/uat'))
        ->map(fn ($file) => $file->getRelativePathname())->values()->all();

    expect($offenders)->toBe([]);
});
