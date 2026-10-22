<?php

namespace App\Console\DemoCommands;

use App\Actions\Flashpoints\SaveFlashpoint;
use App\Actions\Organizations\SaveOrganization;
use App\Actions\Organizations\SaveOrganizationSite;
use App\Actions\Persons\SavePerson;
use App\Actions\PersonsOfInterest\DesignatePersonOfInterest;
use App\Actions\Users\CreateUser;
use App\Models\Flashpoint;
use App\Models\Lga;
use App\Models\Organization;
use App\Models\OrganizationSector;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\ThreatType;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Uat\Uat;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * UAT and LOCAL ONLY: the synthetic records the Phase 11 browser check
 * works on (make browser-check), in the Lagos chief's LGA: an organization
 * with a site, a flashpoint at the LGA's centre, and a person of interest.
 * Idempotent: existing fixtures (matched by name) are kept.
 *
 * On a local development stack, --accounts=<file> also creates the UAT
 * test accounts (SeedUatCommand::ACCOUNTS) when missing, issues them new
 * passwords and TOTP secrets, and writes the credentials table to that
 * file (owner-only) instead of printing it.
 *
 * Refuses anywhere else (production in particular).
 */
class BrowserCheckFixturesCommand extends Command
{
    public const ORGANIZATION = 'UAT Browser Check Haulage Ltd';

    public const SITE = 'UAT Browser Check Depot';

    public const FLASHPOINT = 'UAT Browser Check Junction';

    protected $signature = 'buffalo:uat:browser-fixtures
        {--accounts= : LOCAL ONLY: create the test accounts and write their new credentials to this file}';

    protected $description = 'UAT or LOCAL ONLY: synthetic records (and, locally, accounts) for the Phase 11 browser check';

    public function handle(JurisdictionContext $context, CreateUser $createUser): int
    {
        if (! Uat::enabled() && ! app()->environment('local')) {
            $this->components->error('buffalo:uat:browser-fixtures runs only in the UAT environment or on a local development stack. Refusing.');

            return self::FAILURE;
        }

        $file = $this->option('accounts');
        if (is_string($file) && $file !== '') {
            if (! app()->environment('local')) {
                $this->components->error('--accounts is for a local development stack only (UAT accounts come from buffalo:uat:seed).');

                return self::FAILURE;
            }

            // Console commands run in the system context.
            $rows = SeedUatCommand::issueCredentials(SeedUatCommand::ensureAccounts($createUser));
            $table = implode("\n", array_map(fn (array $r) => "| {$r[0]} | {$r[1]} | {$r[2]} | {$r[3]} |", $rows))."\n";
            $old = umask(0077);
            file_put_contents($file, $table);
            umask($old);
            chmod($file, 0600);
            $this->components->info('Test accounts ready; credentials written to the file given (not printed).');
        }

        $sd = User::query()->where('email', 'sd.lagos@'.Uat::DOMAIN)->first();
        $chief = User::query()->where('email', 'chief.lagos@'.Uat::DOMAIN)->first();

        if ($sd === null || $chief === null) {
            $this->components->error('The UAT accounts do not exist yet: run buffalo:uat:seed first.');

            return self::FAILURE;
        }

        $lga = Lga::query()->whereKey($chief->lga_id)->firstOrFail();
        $centre = DB::table('lgas')->where('id', $lga->id)
            ->selectRaw('ST_Y(centroid::geometry) AS lat, ST_X(centroid::geometry) AS lng')->first();

        $context->actingAs($sd, function () use ($sd, $lga, $centre): void {
            $organization = Organization::query()->where('name', self::ORGANIZATION)->first()
                ?? app(SaveOrganization::class)->create([
                    'name' => self::ORGANIZATION, 'other_names' => [], 'type' => 'company',
                    'organization_sector_id' => OrganizationSector::query()->orderBy('sort_order')->value('id'),
                    'description' => '<p>Synthetic organization for the browser check.</p>', 'status' => 'active',
                ], $sd);

            if (! OrganizationSite::query()->where('name', self::SITE)->exists()) {
                app(SaveOrganizationSite::class)->create($organization, $lga, [
                    'name' => self::SITE, 'site_type' => 'facility', 'security_relevance' => 'high', 'status' => 'active',
                    'has_private_security' => false, 'classification' => 'restricted',
                    'security_notes' => '<p>Synthetic site: the north gate is unmanned at night.</p>',
                    'latitude' => $centre?->lat, 'longitude' => $centre?->lng,
                ], $sd);
            }

            if (! Flashpoint::query()->where('name', self::FLASHPOINT)->exists() && $centre !== null) {
                app(SaveFlashpoint::class)->create($lga, [
                    'kind' => 'flashpoint', 'name' => self::FLASHPOINT,
                    'threat_type_id' => ThreatType::query()->orderBy('sort_order')->value('id'),
                    'community_id' => null, 'latitude' => $centre->lat, 'longitude' => $centre->lng, 'radius_meters' => 1500,
                    'description' => '<p>Synthetic flashpoint for the browser check.</p>', 'classification' => 'restricted',
                ], ['concern_level' => 'moderate', 'status' => 'active', 'assessment' => '<p>Calm, watched weekly.</p>', 'recommended_action' => null], $sd);
            }

            if (! Person::query()->where('first_name', 'Bisi')->where('last_name', 'Checkperson')->exists()) {
                $person = app(SavePerson::class)->create($lga, null, [
                    'first_name' => 'Bisi', 'last_name' => 'Checkperson', 'gender' => 'female',
                    'date_of_birth' => '1991-03-02', 'nin' => '12312312312', 'phone' => '08030000077',
                    'occupation' => 'Driver', 'description' => '<p>Synthetic UAT person (browser check).</p>',
                    'classification' => 'restricted',
                ], [], $sd);
                app(DesignatePersonOfInterest::class)($person, [
                    'risk_level' => 'medium', 'status' => 'at_large', 'offence_categories' => [],
                    'known_offences' => '<p>Synthetic offences.</p>',
                ], 'Browser check fixture.', $sd);
            }
        });

        $this->components->info('Browser check fixtures ready (UAT, synthetic).');

        return self::SUCCESS;
    }
}
