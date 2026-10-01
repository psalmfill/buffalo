<?php

namespace App\Actions\Threats;

use App\Enums\NotificationSeverity;
use App\Enums\ThreatIncidentRelation;
use App\Enums\ThreatStatus;
use App\Enums\ThreatUrgency;
use App\Events\Threats\ThreatReportChanged;
use App\Models\Incident;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Threats\ThreatNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Moves an active threat report to mitigated, materialised or withdrawn
 * (Phase 6B), with the required note, as the named event "status_changed".
 *
 * Materialised needs at least one linked incident: an incident given here
 * is linked (or re-marked) as "materialised as" in the same transaction.
 * The creator and the SD/DSD are told, and national level too when the
 * threat was immediate.
 */
final class ChangeThreatStatus
{
    public function __construct(
        private readonly LinkToThreatReport $link,
        private readonly ThreatNotifier $notifier,
    ) {}

    public function __invoke(ThreatReport $threat, ThreatStatus $status, string $note, ?Incident $incident, User $actor): ThreatReport
    {
        $changed = DB::transaction(function () use ($threat, $status, $note, $incident, $actor): ThreatReport {
            $locked = ThreatReport::query()->whereKey($threat->id)->lockForUpdate()->firstOrFail();

            if (! $locked->isActive() || ! in_array($status, ThreatStatus::settable(), true)) {
                throw ValidationException::withMessages(['status' => 'Only an active threat report can be marked mitigated, materialised or withdrawn.']);
            }

            if ($status === ThreatStatus::Materialised) {
                if ($incident !== null) {
                    $this->link->record($locked, $incident, ThreatIncidentRelation::MaterialisedAs->value, $actor);
                }

                // Any linked incident counts, whether or not this officer can see it.
                if (! DB::table('threat_report_incidents')->where('threat_report_id', $locked->id)->whereNull('deleted_at')->exists()) {
                    throw ValidationException::withMessages(['incident' => 'Link the incident it materialised as first.']);
                }
            }

            $from = $locked->status;
            $locked->forceFill(['status' => $status, 'status_note' => $note, 'updated_by' => $actor->id])->save();

            AuditLog::event('status_changed', "Status changed from {$from->label()} to {$status->label()}", $locked, [
                'from' => $from->value,
                'to' => $status->value,
                'note' => $note,
                ...($incident !== null ? ['incident' => $incident->ulid] : []),
            ], $actor);

            ThreatReportChanged::dispatch($locked);

            return $locked;
        });

        if ($status === ThreatStatus::Materialised) {
            $users = $this->notifier->creator($changed)->merge($this->notifier->stateLeads($changed));

            if ($changed->urgency === ThreatUrgency::Immediate) {
                $users = $users->merge($this->notifier->national($changed));
            }

            $this->notifier->send($changed, 'materialised', $users, NotificationSeverity::Warning, [$actor->id]);
        }

        return $changed;
    }
}
