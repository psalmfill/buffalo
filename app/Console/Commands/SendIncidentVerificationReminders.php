<?php

namespace App\Console\Commands;

use App\Enums\IncidentStatus;
use App\Enums\NotificationSeverity;
use App\Models\Incident;
use App\Support\Incidents\IncidentNotifier;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Every 5 minutes (Phase 5C): submitted incidents still unverified past
 * their deadline (buffalo.incidents.verify_within_minutes, by severity)
 * are brought to the SD and DSD of the state once; at twice the deadline,
 * the DG and the DDGs covering the state are told once. Each reminder is
 * recorded per submission (incident_reminders), so a resubmitted incident
 * starts again.
 */
class SendIncidentVerificationReminders extends Command
{
    protected $signature = 'buffalo:incident-verification-reminders';

    protected $description = 'Remind reviewers of incidents past their verification deadline';

    public function handle(IncidentNotifier $notifier): int
    {
        $now = CarbonImmutable::now();
        $sent = 0;

        $incidents = Incident::query()
            ->with('lga')
            ->where('status', IncidentStatus::Submitted->value)
            ->whereNotNull('submitted_at')
            ->get();

        foreach ($incidents as $incident) {
            /** @var CarbonImmutable $submittedAt */
            $submittedAt = $incident->submitted_at;
            $minutes = (int) config("buffalo.incidents.verify_within_minutes.{$incident->severity->value}");
            $overdue = $submittedAt->addMinutes($minutes);

            if ($now->greaterThanOrEqualTo($overdue) && $this->once($incident, 'verification_overdue', $submittedAt, $now)) {
                $notifier->send($incident, 'verification_overdue', $notifier->stateLeads($incident), NotificationSeverity::Warning);
                $sent++;
            }

            if ($now->greaterThanOrEqualTo($submittedAt->addMinutes(2 * $minutes)) && $this->once($incident, 'verification_overdue_national', $submittedAt, $now)) {
                $notifier->send($incident, 'verification_overdue_national', $notifier->national($incident), NotificationSeverity::Warning);
                $sent++;
            }
        }

        $this->components->info("{$sent} reminder(s) sent.");

        return self::SUCCESS;
    }

    /** Records the reminder; false when it was already sent for this submission. */
    private function once(Incident $incident, string $kind, CarbonImmutable $submittedAt, CarbonImmutable $now): bool
    {
        return DB::table('incident_reminders')->insertOrIgnore([
            'incident_id' => $incident->id,
            'kind' => $kind,
            'for_submission_at' => $submittedAt,
            'sent_at' => $now,
        ]) === 1;
    }
}
