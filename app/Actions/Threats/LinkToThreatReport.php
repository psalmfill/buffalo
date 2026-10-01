<?php

namespace App\Actions\Threats;

use App\Events\Threats\ThreatReportChanged;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Links a flashpoint, criminal group (with its role) or incident (with its
 * relation) to a threat report (Phase 6B), on the report's place. Logged
 * as a named event on the report (ULIDs only). An existing incident link
 * takes the new relation (e.g. related → materialised as).
 */
final class LinkToThreatReport
{
    public function __invoke(ThreatReport $threat, Flashpoint|CriminalGroup|Incident $record, ?string $kind, User $actor): void
    {
        DB::transaction(function () use ($threat, $record, $kind, $actor): void {
            $this->record($threat, $record, $kind, $actor, failIfLinked: true);
            ThreatReportChanged::dispatch($threat);
        });
    }

    /**
     * Inside an open transaction.
     */
    public function record(ThreatReport $threat, Flashpoint|CriminalGroup|Incident $record, ?string $kind, User $actor, bool $failIfLinked = false): void
    {
        [$table, $column, $extra, $key] = match (true) {
            $record instanceof Flashpoint => ['threat_report_flashpoints', 'flashpoint_id', null, 'flashpoint'],
            $record instanceof CriminalGroup => ['threat_report_criminal_groups', 'criminal_group_id', 'role', 'criminal_group'],
            $record instanceof Incident => ['threat_report_incidents', 'incident_id', 'relation', 'incident'],
        };

        $existing = DB::table($table)->where('threat_report_id', $threat->id)->where($column, $record->id)->whereNull('deleted_at')->first();

        if ($existing !== null) {
            if ($extra === null || $existing->{$extra} === $kind) {
                if ($failIfLinked) {
                    throw ValidationException::withMessages(['record' => 'This record is already linked to the threat report.']);
                }

                return;
            }

            DB::table($table)->where('id', $existing->id)->update([$extra => $kind]);
            AuditLog::event("{$key}_link_changed", str_replace('_', ' ', ucfirst($key)).' link changed', $threat, [$key => $record->ulid, 'old' => $existing->{$extra}, 'new' => $kind], $actor);

            return;
        }

        DB::table($table)->insert([
            'ulid' => Str::lower((string) Str::ulid()),
            'threat_report_id' => $threat->id,
            'state_id' => $threat->state_id,
            'lga_id' => $threat->lga_id,
            $column => $record->id,
            ...($extra !== null ? [$extra => $kind] : []),
            'linked_by' => $actor->id,
            'linked_at' => CarbonImmutable::now(),
        ]);

        AuditLog::event("{$key}_linked", str_replace('_', ' ', ucfirst($key)).' linked', $threat, [$key => $record->ulid, ...($extra !== null ? [$extra => $kind] : [])], $actor);
    }
}
