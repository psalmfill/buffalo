<?php

namespace App\Console\DemoCommands;

use App\Actions\Persons\SavePerson;
use App\Actions\Users\CreateUser;
use App\Actions\Users\UserJurisdiction;
use App\Enums\Role;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Support\Uat\Uat;
use Database\Seeders\DevelopmentHierarchySeeder;
use Database\Seeders\ReferenceDataSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Database\Seeders\ZonesAndStatesSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;

/**
 * Seeds the UAT environment (Phase 9B.5) with synthetic data only:
 * the demo hierarchy and boundaries for Lagos and Kano, a few thousand demo
 * incidents, a person in Kano for the cross-state scenario, and one account
 * per role (plus the extra accounts the scripts need), with known passwords
 * and TOTP secrets printed ONCE.
 *
 * Refuses outside APP_ENV=uat, and on any database holding an account
 * that is not a UAT account (a real system).
 */
class SeedUatCommand extends Command
{
    protected $signature = 'buffalo:uat:seed
        {--incidents=3000 : Demo incidents to generate}
        {--reset-credentials : Only issue new passwords and TOTP secrets to the UAT accounts}
        {--if-empty : Do nothing (successfully) when the UAT data already exists}';

    protected $description = 'UAT ONLY: seed synthetic data and the UAT accounts, printing their credentials once';

    /**
     * local part => [role, state code, demo LGA index or null, name]
     *
     * @var array<string, array{0: Role, 1: string|null, 2: int|null, 3: string}>
     */
    public const ACCOUNTS = [
        'admin' => [Role::SuperAdmin, null, null, 'UAT System Administrator'],
        'dg' => [Role::DirectorGeneral, null, null, 'UAT Director General'],
        'ddg' => [Role::DeputyDirectorGeneral, null, null, 'UAT Deputy Director General'],
        'sd.lagos' => [Role::StateDirector, 'LA', null, 'UAT State Director Lagos'],
        'dsd.lagos' => [Role::DeputyStateDirector, 'LA', null, 'UAT Deputy State Director Lagos'],
        'chief.lagos' => [Role::LgaSecurityChief, 'LA', 0, 'UAT LGA Chief Lagos'],
        'sd.kano' => [Role::StateDirector, 'KN', null, 'UAT State Director Kano'],
        'chief.kano' => [Role::LgaSecurityChief, 'KN', 0, 'UAT LGA Chief Kano'],
        'sd.oyo' => [Role::StateDirector, 'OY', null, 'UAT State Director Oyo'],
    ];

    /** States live in UAT; Oyo stays not live for the pilot rollout script. */
    public const LIVE_STATES = ['LA', 'KN'];

    public function handle(CreateUser $createUser, SavePerson $savePerson): int
    {
        if (! Uat::enabled()) {
            $this->components->error('buffalo:uat:seed runs only in the UAT environment (APP_ENV=uat). Refusing.');

            return self::FAILURE;
        }

        $foreign = DB::table('users')->where('email', 'not like', '%@'.Uat::DOMAIN)->count();
        if ($foreign > 0) {
            $this->components->error("This database holds {$foreign} account(s) that are not UAT accounts: it is not a UAT database. Refusing.");

            return self::FAILURE;
        }

        $existing = DB::table('users')->count();
        if ($this->option('reset-credentials')) {
            if ($existing === 0) {
                $this->components->error('No UAT accounts yet: run buffalo:uat:seed first.');

                return self::FAILURE;
            }
            $this->printCredentials(self::issueCredentials(User::query()->orderBy('id')->get()));

            return self::SUCCESS;
        }
        if ($existing > 0 && $this->option('if-empty')) {
            $this->components->info('The UAT data already exists (new credentials: make uat-credentials).');

            return self::SUCCESS;
        }
        if ($existing > 0) {
            $this->components->error('The UAT data already exists. Use --reset-credentials for new passwords, or recreate the environment (make uat-down, make uat-up).');

            return self::FAILURE;
        }

        foreach ([RolesAndPermissionsSeeder::class, ZonesAndStatesSeeder::class, ReferenceDataSeeder::class, DevelopmentHierarchySeeder::class] as $seeder) {
            $this->call('db:seed', ['--class' => $seeder, '--force' => true]);
        }

        State::query()->whereIn('code', self::LIVE_STATES)->get()
            ->each(fn (State $state) => $state->forceFill(['is_live' => true, 'went_live_at' => now()])->save());

        $users = self::ensureAccounts($createUser);

        // Incidents first: once boundaries exist, a trigger rejects points
        // outside their LGA, and demo points are spread around a centre.
        $this->call('buffalo:seed-demo-incidents', ['--count' => (int) $this->option('incidents')]);
        $this->call('buffalo:generate-demo-boundaries');

        // The cross-state scenario: a person recorded in Kano, searched for
        // from Lagos by NIN, or by name and date of birth.
        $kanoChief = $users->firstOrFail(fn (User $u) => $u->email === 'chief.kano@'.Uat::DOMAIN);
        $kanoLga = Lga::query()->whereKey($kanoChief->lga_id)->firstOrFail();
        $savePerson->create($kanoLga, null, [
            'first_name' => 'Garba', 'last_name' => 'Testperson', 'gender' => 'male',
            'date_of_birth' => '1985-04-12', 'nin' => '98765432101', 'phone' => '08030000099',
            'occupation' => 'Trader', 'description' => 'Synthetic UAT person (cross-state scenario).',
            'classification' => 'restricted',
        ], [], $kanoChief);

        $this->printCredentials(self::issueCredentials($users));
        $this->components->warn('UAT data only. Record the credentials now: they are not shown again (use --reset-credentials for new ones).');

        return self::SUCCESS;
    }

    /**
     * The UAT accounts (created when missing), in ACCOUNTS order.
     *
     * @return Collection<int, User>
     */
    public static function ensureAccounts(CreateUser $createUser): Collection
    {
        /** @var Collection<int, User> $users */
        $users = new Collection;
        foreach (self::ACCOUNTS as $local => [$role, $stateCode, $lgaIndex, $name]) {
            $email = "{$local}@".Uat::DOMAIN;
            $existing = User::query()->where('email', $email)->first();

            if ($existing !== null) {
                $users->push($existing);

                continue;
            }

            $state = $stateCode === null ? null : State::query()->where('code', $stateCode)->firstOrFail();
            $lga = $lgaIndex === null || $state === null ? null : Lga::query()->where('state_id', $state->id)->orderBy('code')->skip($lgaIndex)->firstOrFail();

            $users->push($createUser(['name' => $name, 'email' => $email], new UserJurisdiction($role, $state, $lga)));
        }

        return $users;
    }

    /**
     * New password and TOTP secret for each account; returns what to print.
     *
     * @param  Collection<int, User>  $users
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string}>
     */
    public static function issueCredentials(Collection $users): array
    {
        $google2fa = app(Google2FA::class);
        $rows = [];

        foreach ($users as $user) {
            $password = self::password();
            $secret = $google2fa->generateSecretKey();

            $user->forceFill([
                'password' => $password,
                'password_changed_at' => now(),
                'two_factor_secret' => encrypt($secret),
                'two_factor_recovery_codes' => encrypt((string) json_encode(Collection::times(8, fn () => RecoveryCode::generate())->all())),
                'two_factor_confirmed_at' => now(),
            ])->save();

            // otpauth:// URI: scanned as a QR code, or opened on the phone,
            // it adds the account to an authenticator app.
            $uri = $google2fa->getQRCodeUrl(config('app.name').' UAT', $user->email, $secret);
            $rows[] = [$user->email, $user->getRole()?->label() ?? '', $password, $secret, $uri];
        }

        return $rows;
    }

    /**
     * A password meeting the rules (mixed case, digits, symbols) without
     * characters that break the printed table or a shell (| ' " ` $ \\ space).
     */
    public static function password(): string
    {
        $sets = ['ABCDEFGHJKLMNPQRSTUVWXYZ', 'abcdefghijkmnpqrstuvwxyz', '23456789', '!#%*+-=?@^_'];
        $chars = array_map(fn (string $set) => $set[random_int(0, strlen($set) - 1)], $sets);
        $all = implode('', $sets);
        while (count($chars) < 20) {
            $chars[] = $all[random_int(0, strlen($all) - 1)];
        }
        shuffle($chars);

        return implode('', $chars);
    }

    /**
     * Printed once (nothing keeps the passwords): the table (also read by
     * scripts/uat/run.py), then each account's TOTP setup URI.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string}>  $rows
     */
    private function printCredentials(array $rows): void
    {
        $this->newLine();
        $this->table(['Email', 'Role', 'Password', 'TOTP secret (add to an authenticator app)'], array_map(fn (array $row) => array_slice($row, 0, 4), $rows));
        $this->newLine();
        $this->line('TOTP setup URIs (open on the phone, or turn into a QR code, to add the account to an authenticator app):');
        foreach ($rows as $row) {
            $this->line("  {$row[0]}  {$row[4]}");
        }
    }
}
