<?php

namespace App\Actions\Disseminations;

use App\Enums\NotificationSeverity;
use App\Models\RecordDissemination;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Disseminations\DisseminationNotifier;
use App\Support\Jurisdiction\DisseminationSubjects;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A recipient acknowledges a dissemination (Phase 7A): once, named audit
 * event "acknowledged", the disseminator is told.
 */
final class AcknowledgeDissemination
{
    public function __construct(private readonly DisseminationNotifier $notifier) {}

    public function __invoke(RecordDissemination $dissemination, User $actor): void
    {
        $updated = DB::transaction(function () use ($dissemination, $actor): bool {
            $changed = RecordDissemination::query()->whereKey($dissemination->id)->whereNull('acknowledged_at')
                ->update(['acknowledged_by' => $actor->id, 'acknowledged_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]) === 1;

            if ($changed) {
                $dissemination->refresh()->loadMissing(['targetState', 'targetLga']);
                AuditLog::event('acknowledged', "Acknowledged by {$dissemination->targetName()}", $dissemination, ['target' => $dissemination->targetName()], $actor);
            }

            return $changed;
        });

        $subject = DisseminationSubjects::find($dissemination);

        if ($updated && $subject !== null) {
            $this->notifier->send($this->notifier->users($dissemination->disseminated_by), $subject, 'acknowledged', NotificationSeverity::Info, $this->notifier->source($dissemination), place: $dissemination->targetName());
        }
    }
}
