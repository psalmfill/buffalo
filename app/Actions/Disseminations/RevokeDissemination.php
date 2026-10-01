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
 * Revokes a dissemination and, with it, every onward dissemination made
 * from it (Phase 7A). Access ends at once: recipients are told (by
 * clearance), and pages open through it close (DisseminationEnded).
 * Named audit event "revoked" on each row.
 */
final class RevokeDissemination
{
    public function __construct(private readonly DisseminationNotifier $notifier) {}

    public function __invoke(RecordDissemination $dissemination, string $reason, User $actor): int
    {
        $revoked = DB::transaction(function () use ($dissemination, $reason, $actor): array {
            $rows = [];
            $queue = [$dissemination->id];
            $now = CarbonImmutable::now();

            while ($queue !== []) {
                $id = array_shift($queue);
                $row = RecordDissemination::query()->whereKey($id)->whereNull('revoked_at')->lockForUpdate()->first();

                if ($row !== null) {
                    $row->forceFill(['revoked_at' => $now, 'revoked_by' => $actor->id, 'revocation_reason' => $row->id === $dissemination->id ? trim($reason) : 'The dissemination it was forwarded from was revoked.'])->save();
                    $row->loadMissing(['targetState', 'targetLga']);
                    AuditLog::event('revoked', "Sharing with {$row->targetName()} revoked", $row, [
                        'target' => $row->targetName(),
                        'cascade' => $row->id !== $dissemination->id,
                    ], $actor);
                    $rows[] = $row;
                }

                array_push($queue, ...RecordDissemination::query()->where('parent_dissemination_id', $id)->whereNull('revoked_at')->pluck('id')->all());
            }

            return $rows;
        });

        foreach ($revoked as $row) {
            $subject = DisseminationSubjects::find($row);

            if ($subject !== null) {
                $this->notifier->toRecipients($row, $subject, 'revoked', NotificationSeverity::Info);
            }

            $this->notifier->broadcastEnded($row);
        }

        return count($revoked);
    }
}
