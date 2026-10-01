<?php

namespace App\Actions\Threats;

use App\Events\Threats\ThreatReportChanged;
use App\Models\ThreatReport;
use App\Models\ThreatReportCriminalGroup;
use App\Models\ThreatReportFlashpoint;
use App\Models\ThreatReportIncident;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Removes a threat report link (Phase 6B): soft-deleted with who removed
 * it, and logged on the report.
 */
final class UnlinkFromThreatReport
{
    public function __invoke(ThreatReport $threat, ThreatReportFlashpoint|ThreatReportCriminalGroup|ThreatReportIncident $link, User $actor): void
    {
        DB::transaction(function () use ($threat, $link, $actor): void {
            [$key, $ulid] = match (true) {
                $link instanceof ThreatReportFlashpoint => ['flashpoint', $link->flashpoint->ulid],
                $link instanceof ThreatReportCriminalGroup => ['criminal_group', $link->criminalGroup->ulid],
                $link instanceof ThreatReportIncident => ['incident', $link->incident->ulid],
            };

            $link->forceFill(['unlinked_by' => $actor->id])->save();
            $link->delete();

            AuditLog::event("{$key}_unlinked", str_replace('_', ' ', ucfirst($key)).' unlinked', $threat, [$key => $ulid], $actor);
            ThreatReportChanged::dispatch($threat);
        });
    }
}
