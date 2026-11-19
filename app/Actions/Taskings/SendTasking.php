<?php

namespace App\Actions\Taskings;

use App\Enums\Role;
use App\Enums\TaskingStatus;
use App\Enums\TaskingTargetMode;
use App\Models\Lga;
use App\Models\Tasking;
use App\Models\TaskingRecipient;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Classification\ClassificationGate;
use App\Support\Taskings\TaskingNotifier;
use App\Support\Taskings\TaskingReferenceAllocator;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sends a draft tasking (Phase 11H), in one transaction:
 *
 * 1. the draft row is locked, so a double click sends once;
 * 2. every chosen LGA is re-checked: active and in the tasking's own state
 *    (the request checked the sender's reach; this is the last word);
 * 3. the body must have text, and LGA chiefs must be cleared for the
 *    tasking's classification (otherwise nobody could read it);
 * 4. one recipient row per LGA, the reference (gap-free), the target mode
 *    derived from the count (one, some, or every active LGA of the state),
 *    and status `sent`. From then on the content is fixed (trigger).
 *
 * After the commit, each recipient LGA's current chief is notified at the
 * priority's severity, and dashboards refresh live.
 */
final class SendTasking
{
    public function __construct(
        private readonly TaskingReferenceAllocator $references,
        private readonly TaskingNotifier $notifier,
        private readonly ClassificationGate $gate,
    ) {}

    /**
     * @param  list<int>  $lgaIds
     */
    public function __invoke(Tasking $tasking, array $lgaIds, User $actor): Tasking
    {
        $lgaIds = array_values(array_unique(array_map('intval', $lgaIds)));

        DB::transaction(function () use ($tasking, $lgaIds, $actor): void {
            /** @var Tasking $locked */
            $locked = Tasking::query()->whereKey($tasking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== TaskingStatus::Draft) {
                throw ValidationException::withMessages(['tasking' => 'This tasking has already been sent.']);
            }

            if (trim((string) $locked->body_text) === '') {
                throw ValidationException::withMessages(['body' => 'Write the instruction before sending it.']);
            }

            if (! self::chiefsCanSee($locked, $this->gate)) {
                throw ValidationException::withMessages(['classification' => self::CLASSIFICATION_PROBLEM]);
            }

            $active = Lga::query()->active()->where('lgas.state_id', $locked->state_id)->pluck('lgas.id')->map(fn (mixed $id) => (int) $id)->all();

            if ($lgaIds === []) {
                throw ValidationException::withMessages(['lgas' => 'Choose at least one LGA.']);
            }

            if (array_diff($lgaIds, $active) !== []) {
                throw ValidationException::withMessages(['lgas' => 'Taskings can be sent only to active LGAs of your own state.']);
            }

            $now = CarbonImmutable::now();

            foreach ($lgaIds as $lgaId) {
                $recipient = new TaskingRecipient;
                $recipient->forceFill([
                    'ulid' => (string) Str::ulid(),
                    'tasking_id' => $locked->id,
                    'state_id' => $locked->state_id,
                    'lga_id' => $lgaId,
                ])->saveQuietly();
            }

            $mode = TaskingTargetMode::derive(count($lgaIds), count($active));

            $locked->forceFill([
                'reference_no' => $this->references->allocate($locked->state_id, $now),
                'status' => TaskingStatus::Sent,
                'target_mode' => $mode,
                'sent_by' => $actor->id,
                'sent_at' => $now,
                'draft_lga_ids' => null,
                'updated_by' => $actor->id,
            ])->save();

            AuditLog::event('tasking_sent', 'Tasking sent to '.count($lgaIds).' LGA'.(count($lgaIds) === 1 ? '' : 's'), $locked, [
                'reference' => $locked->reference_no,
                'target_mode' => $mode->value,
                'recipients' => count($lgaIds),
                'priority' => $locked->priority->value,
            ], $actor);

            $tasking->setRawAttributes($locked->getAttributes(), true);
        });

        $this->notifier->toChiefs('sent', $tasking, $tasking->priority->severity());

        return $tasking;
    }

    public const CLASSIFICATION_PROBLEM = 'LGA Security Chiefs are not cleared for this classification, so they could not read the tasking. Choose Restricted to send it.';

    /** Whether LGA chiefs are cleared for the tasking's level. */
    public static function chiefsCanSee(Tasking $tasking, ClassificationGate $gate): bool
    {
        $max = $gate::maxLevelForRole(Role::LgaSecurityChief);

        return $max !== null && $tasking->classificationLevel()->rank() <= $max->rank();
    }
}
