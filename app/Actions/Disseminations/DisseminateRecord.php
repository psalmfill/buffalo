<?php

namespace App\Actions\Disseminations;

use App\Enums\DisseminationRequestStatus;
use App\Enums\DisseminationTargetLevel;
use App\Models\DisseminationRequest;
use App\Models\Flashpoint;
use App\Models\Lga;
use App\Models\RecordDissemination;
use App\Models\State;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Disseminations\DisseminationNotifier;
use App\Support\Jurisdiction\Contracts\Disseminable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Disseminates a threat report or flashpoint to states or LGAs (Phase 7A),
 * or forwards a received dissemination to LGAs of the recipient's own state
 * (onward: $parent set). The caller has authorised `disseminate` or
 * `onward`.
 *
 * - A target must lie outside the record's own jurisdiction view: never the
 *   record's own state as a whole, nor its own LGA. Onward targets are LGAs
 *   of the parent's target state only.
 * - Expiry: a threat report's own expiry (kept in sync afterwards); a
 *   flashpoint's `flashpoint_days` from now. Onward ones end with their
 *   parent.
 * - One active dissemination per record and target. An expired one for
 *   the same target is superseded (revoked) by the new one.
 * - Named audit event "disseminated" on each row; recipients notified.
 */
final class DisseminateRecord
{
    public function __construct(private readonly DisseminationNotifier $notifier) {}

    /**
     * @param  list<array{level: DisseminationTargetLevel, state: State, lga: Lga|null}>  $targets
     * @return list<RecordDissemination>
     */
    public function __invoke(Model&Disseminable $subject, array $targets, string $reason, User $actor, ?RecordDissemination $parent = null, ?DisseminationRequest $request = null): array
    {
        if ($targets === []) {
            throw ValidationException::withMessages(['targets' => 'Choose at least one state or LGA.']);
        }

        $created = DB::transaction(function () use ($subject, $targets, $reason, $actor, $parent, $request): array {
            $locked = $subject->newQuery()->whereKey($subject->getKey())->lockForUpdate()->firstOrFail();
            $expiresAt = $this->expiry($locked, $parent);
            $rows = [];

            foreach ($targets as $target) {
                $this->check($locked, $target, $parent);
                $this->supersedeExpired($locked, $target, $actor);

                $row = new RecordDissemination;
                $row->forceFill([
                    'subject_type' => $locked->disseminationType(),
                    'subject_id' => $locked->getKey(),
                    'source_state_id' => $locked->getAttribute('state_id'),
                    'source_lga_id' => $locked->getAttribute('lga_id'),
                    'target_level' => $target['level'],
                    'target_state_id' => $target['state']->id,
                    'target_lga_id' => $target['lga']?->id,
                    'parent_dissemination_id' => $parent?->id,
                    'reason' => trim($reason),
                    'disseminated_by' => $actor->id,
                    'disseminated_at' => CarbonImmutable::now(),
                    'expires_at' => $expiresAt,
                ])->save();
                $row->setRelation('targetState', $target['state'])->setRelation('targetLga', $target['lga']);

                AuditLog::event('disseminated', "{$locked->disseminationKind()} {$locked->disseminationLabel()} shared with {$row->targetName()}", $row, [
                    'subject_type' => $row->subject_type,
                    'subject' => $locked->getAttribute('ulid'),
                    'target' => $row->targetName(),
                    'onward' => $parent?->ulid,
                    'expires_at' => $expiresAt->toIso8601String(),
                ], $actor);

                $rows[] = $row;
            }

            if ($request !== null && $request->status === DisseminationRequestStatus::Pending) {
                $request->forceFill([
                    'status' => DisseminationRequestStatus::Actioned,
                    'decided_by' => $actor->id,
                    'decided_at' => CarbonImmutable::now(),
                ])->save();
            }

            return $rows;
        });

        foreach ($created as $row) {
            $this->notifier->toRecipients($row, $subject, 'disseminated', $subject->disseminationSeverity());
        }

        return $created;
    }

    private function expiry(Model&Disseminable $subject, ?RecordDissemination $parent): CarbonImmutable
    {
        if ($parent !== null) {
            return $parent->expires_at;
        }

        if ($subject instanceof ThreatReport) {
            if (! $subject->isActive() || ! $subject->expires_at->isFuture()) {
                throw ValidationException::withMessages(['targets' => 'Only an active threat report can be disseminated.']);
            }

            return $subject->expires_at;
        }

        return CarbonImmutable::now()->addDays((int) config('buffalo.disseminations.flashpoint_days'));
    }

    /**
     * @param  array{level: DisseminationTargetLevel, state: State, lga: Lga|null}  $target
     */
    private function check(Model&Disseminable $subject, array $target, ?RecordDissemination $parent): void
    {
        $name = $target['lga'] !== null ? $target['lga']->name.' LGA' : $target['state']->name.' State';

        if ($target['level'] === DisseminationTargetLevel::Lga && ($target['lga'] === null || $target['lga']->state_id !== $target['state']->id)) {
            throw ValidationException::withMessages(['targets' => 'Choose a valid LGA.']);
        }

        if ($target['level'] === DisseminationTargetLevel::State && $target['state']->id === (int) $subject->getAttribute('state_id')) {
            throw ValidationException::withMessages(['targets' => "{$name} already holds this record: choose LGAs of the state instead."]);
        }

        if ($target['lga'] !== null && $target['lga']->id === (int) $subject->getAttribute('lga_id')) {
            throw ValidationException::withMessages(['targets' => "{$name} already holds this record."]);
        }

        if ($parent !== null && ($target['level'] !== DisseminationTargetLevel::Lga || $target['state']->id !== $parent->target_state_id)) {
            throw ValidationException::withMessages(['targets' => 'A shared record can only be forwarded to LGAs of your own state.']);
        }

        $exists = RecordDissemination::query()->about($subject)->active()
            ->where('target_level', $target['level']->value)
            ->where('target_state_id', $target['state']->id)
            ->when($target['lga'] !== null, fn ($q) => $q->where('target_lga_id', $target['lga']?->id))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['targets' => "Already shared with {$name}."]);
        }
    }

    /**
     * @param  array{level: DisseminationTargetLevel, state: State, lga: Lga|null}  $target
     */
    private function supersedeExpired(Model&Disseminable $subject, array $target, User $actor): void
    {
        $expired = RecordDissemination::query()->about($subject)
            ->whereNull('revoked_at')
            ->where('expires_at', '<=', CarbonImmutable::now())
            ->where('target_level', $target['level']->value)
            ->where('target_state_id', $target['state']->id)
            ->when($target['lga'] !== null, fn ($q) => $q->where('target_lga_id', $target['lga']?->id))
            ->get();

        foreach ($expired as $row) {
            $row->forceFill(['revoked_at' => CarbonImmutable::now(), 'revoked_by' => $actor->id, 'revocation_reason' => 'Superseded by a new dissemination.'])->save();
        }
    }
}
