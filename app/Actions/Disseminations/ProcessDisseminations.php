<?php

namespace App\Actions\Disseminations;

use App\Enums\NotificationSeverity;
use App\Models\RecordDissemination;
use App\Support\Audit\AuditLog;
use App\Support\Disseminations\DisseminationNotifier;
use App\Support\Jurisdiction\DisseminationSubjects;
use Carbon\CarbonImmutable;

/**
 * Runs every 15 minutes in the system context (Phase 7A):
 * - disseminations past their expiry: named audit event "expired" (no
 *   causer), recipients told once, open pages closed;
 * - active ones still unacknowledged after the record's acknowledgement
 *   window (2 hours for immediate threats, 24 hours otherwise): recipients
 *   reminded once.
 */
final class ProcessDisseminations
{
    public function __construct(private readonly DisseminationNotifier $notifier) {}

    /**
     * @return array{expired: int, reminded: int}
     */
    public function __invoke(): array
    {
        $now = CarbonImmutable::now();
        $expired = 0;

        $ended = RecordDissemination::query()->whereNull('revoked_at')->whereNull('expiry_notified_at')
            ->where('expires_at', '<=', $now)->with(['targetState', 'targetLga'])->get();

        foreach ($ended as $row) {
            if (RecordDissemination::query()->whereKey($row->id)->whereNull('expiry_notified_at')->update(['expiry_notified_at' => $now]) !== 1) {
                continue;
            }

            AuditLog::event('expired', "Sharing with {$row->targetName()} expired", $row, ['expires_at' => $row->expires_at->toIso8601String()]);
            $subject = DisseminationSubjects::find($row);

            if ($subject !== null) {
                $this->notifier->toRecipients($row, $subject, 'expired', NotificationSeverity::Info);
            }

            $this->notifier->broadcastEnded($row);
            $expired++;
        }

        $reminded = 0;
        $pending = RecordDissemination::query()->active()->whereNull('acknowledged_at')->whereNull('reminded_at')
            ->where('disseminated_at', '<=', $now->subHours((int) config('buffalo.disseminations.remind_immediate_hours')))
            ->get();

        foreach ($pending as $row) {
            $subject = DisseminationSubjects::find($row);

            if ($subject === null || $row->disseminated_at->greaterThan($now->subHours($subject->acknowledgementHours()))) {
                continue;
            }

            RecordDissemination::query()->whereKey($row->id)->update(['reminded_at' => $now]);
            $this->notifier->toRecipients($row, $subject, 'reminder', NotificationSeverity::Warning);
            $reminded++;
        }

        return ['expired' => $expired, 'reminded' => $reminded];
    }
}
