<?php

namespace App\Console\DemoCommands;

use App\Models\Incident;
use App\Support\GoLive\DemoData;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * LOCAL ONLY (Phase 5D): fills the database with synthetic incidents for
 * performance checks. Refuses to run outside the `local` environment.
 *
 * Demo incidents are obviously fake: titles start with "[Demo]" and
 * reference numbers with "DEMO/" (never the BUF/ sequence). They spread
 * over the active LGAs, the last 365 days, all severities, statuses and
 * classifications; most have a location near their state's centre and
 * some have casualty rows. Rows are inserted in bulk (no activity log).
 *
 * --purge removes them again. Closed and invalid incidents are locked by
 * triggers, so purging switches the session to replication mode (triggers
 * off), which needs a database superuser: local development only.
 */
class SeedDemoIncidents extends Command
{
    protected $signature = 'buffalo:seed-demo-incidents
        {--count=50000 : Number of incidents}
        {--purge : Remove all demo incidents instead}
        {--hotspot= : "lat,lng": add a tight cluster of demo incidents there instead (Phase 6C)}
        {--hotspot-count=20 : Incidents in the hotspot}
        {--hotspot-type=Kidnapping : Incident type name for the hotspot}';

    protected $description = 'LOCAL ONLY: seed synthetic incidents for performance checks';

    /** Rough state centres for placing demo points (synthetic data only). */
    private const CENTRES = ['LA' => [6.52, 3.37], 'KN' => [12.0, 8.52], 'FC' => [9.07, 7.40], 'RI' => [4.82, 7.03]];

    public function handle(): int
    {
        if (! DemoData::allowed()) {
            $this->components->error('buffalo:seed-demo-incidents runs only in the local and UAT environments.');

            return self::FAILURE;
        }

        if ($this->option('purge')) {
            return $this->purge();
        }

        if (is_string($this->option('hotspot'))) {
            return $this->hotspot((string) $this->option('hotspot'));
        }

        $count = max(1, (int) $this->option('count'));
        $types = DB::table('incident_types')->pluck('id')->all();
        $lgas = DB::table('lgas')->join('states', 'states.id', '=', 'lgas.state_id')->where('lgas.is_active', true)
            ->get(['lgas.id', 'lgas.state_id', 'lgas.code as lga_code', 'states.code as state_code']);
        $officers = DB::table('users')->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('roles.name', '!=', 'super_admin')->where('users.is_active', true)
            ->pluck('users.id')->all();

        if ($types === [] || $lgas->isEmpty() || $officers === []) {
            $this->components->error('Needs incident types, active LGAs and at least one intelligence user.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $sequence = (int) DB::table('incidents')->where('reference_no', 'like', 'DEMO/%')->count();
        $bar = $this->output->createProgressBar($count);

        foreach (array_chunk(range(1, $count), 1000) as $chunk) {
            DB::transaction(function () use ($chunk, $types, $lgas, $officers, $now, &$sequence, $bar): void {
                $rows = [];
                $finals = [];

                foreach ($chunk as $_) {
                    $lga = $lgas->random();
                    $occurred = $now->subMinutes((int) (pow(mt_rand() / mt_getrandmax(), 1.6) * 365 * 24 * 60));
                    $status = $this->pick(['submitted' => 14, 'returned' => 3, 'verified' => 15, 'under_investigation' => 14, 'resolved' => 20, 'closed' => 27, 'invalid' => 4, 'draft' => 3]);
                    $severity = $this->pick(['low' => 30, 'medium' => 40, 'high' => 22, 'critical' => 8]);
                    $submitted = $status === 'draft' ? null : $occurred->addMinutes(mt_rand(10, 600));
                    $officer = $officers[array_rand($officers)];
                    $centre = self::CENTRES[$lga->state_code] ?? [mt_rand(450, 1300) / 100, mt_rand(300, 1350) / 100];
                    $located = mt_rand(1, 100) <= 80;
                    $escalated = $submitted !== null && ($severity === 'critical' || mt_rand(1, 100) <= 3);
                    $sequence++;
                    $ulid = strtolower((string) Str::ulid());

                    $rows[] = [
                        'ulid' => $ulid,
                        'reference_no' => $submitted === null ? null : sprintf('DEMO/%s/%s/%d/%07d', $lga->state_code, $lga->lga_code, $submitted->year, $sequence),
                        'incident_type_id' => $types[array_rand($types)],
                        'details' => '{}',
                        'details_schema_version' => 1,
                        'title' => '[Demo] Synthetic incident '.$sequence,
                        'description' => 'Synthetic incident generated for performance testing. Not real.',
                        'severity' => $severity,
                        'occurred_at' => $occurred,
                        'occurred_at_precision' => 'approximate',
                        'state_id' => $lga->state_id,
                        'lga_id' => $lga->id,
                        'location' => $located ? Incident::pointFrom($centre[0] + (mt_rand(-3000, 3000) / 10000), $centre[1] + (mt_rand(-3000, 3000) / 10000)) : null,
                        // Locked statuses are set after the casualty rows go in.
                        'status' => in_array($status, ['closed', 'invalid'], true) ? 'resolved' : $status,
                        'classification' => $this->pick(['restricted' => 85, 'confidential' => 12, 'secret' => 3]),
                        'reporting_officer_id' => $officer,
                        'created_by' => $officer,
                        'submitted_by' => $submitted === null ? null : $officer,
                        'submitted_at' => $submitted,
                        'escalated_at' => $escalated ? $submitted : null,
                        'escalation_reason' => $escalated ? 'Demo escalation' : null,
                        'escalation_acknowledged_at' => $escalated && mt_rand(0, 1) === 1 ? $submitted->addMinutes(30) : null,
                        'created_at' => $occurred,
                        'updated_at' => $submitted ?? $occurred,
                    ];

                    if (in_array($status, ['closed', 'invalid'], true)) {
                        $finals[$ulid] = $status;
                    }
                }

                DB::table('incidents')->insert($rows);
                $ids = DB::table('incidents')->whereIn('ulid', array_column($rows, 'ulid'))->get(['id', 'ulid', 'state_id', 'lga_id']);

                $casualties = [];
                foreach ($ids as $incident) {
                    if (mt_rand(1, 100) > 40) {
                        continue;
                    }
                    $outcomes = ['killed', 'injured', 'abducted', 'rescued', 'arrested'];
                    shuffle($outcomes);

                    foreach (array_slice($outcomes, 0, mt_rand(1, 2)) as $outcome) {
                        $casualties[] = [
                            'ulid' => strtolower((string) Str::ulid()), 'incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id,
                            'outcome' => $outcome, 'group' => 'civilian', 'adult_male' => mt_rand(1, 6), 'adult_female' => mt_rand(0, 3),
                            'child_male' => 0, 'child_female' => 0, 'unknown' => 0, 'created_at' => now(), 'updated_at' => now(),
                        ];
                    }
                }
                foreach (array_chunk($casualties, 1000) as $batch) {
                    DB::table('incident_casualties')->insert($batch);
                }

                foreach (['closed', 'invalid'] as $final) {
                    $ulids = array_keys(array_filter($finals, fn (string $s) => $s === $final));
                    if ($ulids !== []) {
                        DB::table('incidents')->whereIn('ulid', $ulids)->update(['status' => $final, ...($final === 'invalid' ? ['invalid_reason' => 'false_report'] : [])]);
                    }
                }

                $bar->advance(count($chunk));
            });
        }

        $bar->finish();
        $this->newLine();
        // ANALYZE needs the owner role (Phase 8A).
        DB::connection('pgsql_migrations')->statement('ANALYZE incidents');
        DB::connection('pgsql_migrations')->statement('ANALYZE incident_casualties');
        $this->components->info("{$count} demo incidents added.");

        return self::SUCCESS;
    }

    /**
     * A tight cluster of verified demo incidents (within about 250 m) in the
     * LGA whose boundary contains the point, over the last 60 days: a
     * blackspot for the Phase 6C suggestion job to find.
     */
    private function hotspot(string $point): int
    {
        [$lat, $lng] = array_map('floatval', explode(',', $point));
        $lga = DB::table('lgas')->join('states', 'states.id', '=', 'lgas.state_id')
            ->whereNotNull('lgas.boundary')
            ->whereRaw('ST_Covers(lgas.boundary, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography)', [$lng, $lat])
            ->first(['lgas.id', 'lgas.state_id', 'lgas.code as lga_code', 'states.code as state_code']);
        $type = DB::table('incident_types')->where('name', $this->option('hotspot-type'))->value('id');
        $officer = DB::table('users')->where('state_id', $lga->state_id ?? 0)->value('id') ?? DB::table('users')->value('id');

        if ($lga === null || $type === null) {
            $this->components->error('The point must lie inside an LGA boundary, and the incident type must exist.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        // After the highest demo sequence (drafts have no reference, so not a count).
        $sequence = (int) DB::table('incidents')->where('reference_no', 'like', 'DEMO/%')
            ->selectRaw("max(split_part(reference_no, '/', 5)::int) AS last")->value('last');
        $rows = [];

        foreach (range(1, max(1, (int) $this->option('hotspot-count'))) as $_) {
            $sequence++;
            $occurred = $now->subMinutes(mt_rand(60, 60 * 24 * 60));
            $rows[] = [
                'ulid' => strtolower((string) Str::ulid()),
                'reference_no' => sprintf('DEMO/%s/%s/%d/%07d', $lga->state_code, $lga->lga_code, $occurred->year, $sequence),
                'incident_type_id' => $type,
                'details' => '{}',
                'details_schema_version' => 1,
                'title' => '[Demo] Hotspot incident '.$sequence,
                'description' => 'Synthetic hotspot incident generated for blackspot suggestions. Not real.',
                'severity' => 'high',
                'occurred_at' => $occurred,
                'occurred_at_precision' => 'approximate',
                'state_id' => $lga->state_id,
                'lga_id' => $lga->id,
                'location' => Incident::pointFrom($lat + mt_rand(-200, 200) / 100000, $lng + mt_rand(-200, 200) / 100000),
                'status' => 'verified',
                'classification' => 'restricted',
                'reporting_officer_id' => $officer,
                'created_by' => $officer,
                'submitted_by' => $officer,
                'submitted_at' => $occurred->addMinutes(30),
                'created_at' => $occurred,
                'updated_at' => $occurred->addMinutes(30),
            ];
        }

        DB::table('incidents')->insert($rows);
        $this->components->info(count($rows).' demo hotspot incidents added.');

        return self::SUCCESS;
    }

    /**
     * Hard-deletes demo incidents past their locks, so it needs the
     * superuser connection (Phase 8A: the application role cannot bypass
     * triggers). Development only.
     */
    private function purge(): int
    {
        if (app()->isProduction()) {
            $this->components->error('Demo data cannot be purged in production.');

            return self::FAILURE;
        }

        $admin = DB::connection('pgsql_admin');
        $admin->transaction(function () use ($admin): void {
            $admin->statement("SET LOCAL session_replication_role = 'replica'");
            $ids = $admin->table('incidents')->where('title', 'like', '[Demo]%')->select('id');
            $admin->table('incident_casualties')->whereIn('incident_id', $ids)->delete();
            $deleted = $admin->table('incidents')->where('title', 'like', '[Demo]%')->delete();
            $this->components->info("{$deleted} demo incidents removed.");
        });

        return self::SUCCESS;
    }

    /**
     * @param  array<string, int>  $weights
     */
    private function pick(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $value => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return $value;
            }
        }

        return (string) array_key_first($weights);
    }
}
