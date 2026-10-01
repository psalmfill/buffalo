<?php

namespace App\Http\Presenters;

use App\Enums\Classification;
use App\Enums\DisseminationRequestStatus;
use App\Enums\DisseminationTargetLevel;
use App\Enums\Role;
use App\Enums\ThreatUrgency;
use App\Models\DisseminationRequest;
use App\Models\Lga;
use App\Models\RecordDissemination;
use App\Models\State;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Disseminations\DisseminationNotifier;
use App\Support\Jurisdiction\Contracts\Disseminable;
use App\Support\Jurisdiction\DisseminationTargets;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\OfficerNames;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Dissemination data for pages (Phase 7A):
 * - sharing(): the owners' "Shared with" panel (null for recipients);
 * - banner(): the recipients' read-only banner (null for owners);
 * - row(): one dissemination in the inbox.
 *
 * Nothing about the record itself is added here: subjects are presented by
 * their own presenters, through the viewer's scopes.
 */
final class DisseminationPresenter
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly DisseminationTargets $targets,
        private readonly DisseminationNotifier $notifier,
    ) {}

    /**
     * @return array<string, mixed>|null
     */
    public function sharing(Model&Disseminable $subject, User $user): ?array
    {
        if (! $this->jurisdiction->canAccessOwn($user, $subject)) {
            return null;
        }

        $canDisseminate = $user->can('disseminate', $subject);
        $canRequest = $user->can('request', $subject);
        $rows = RecordDissemination::query()->about($subject)
            ->with(['targetState', 'targetLga'])
            ->orderByRaw('revoked_at IS NOT NULL')
            ->orderByDesc('disseminated_at')
            ->get();
        $suggested = $this->targets->present($this->targets->suggest($subject));
        $uncovered = collect($this->targets->present($this->targets->uncovered($subject)))->pluck('ulid')->all();
        $requests = DisseminationRequest::query()
            ->where('subject_type', $subject->disseminationType())
            ->where('subject_id', $subject->getKey())
            ->latest('id')
            ->get();

        return [
            'disseminations' => $this->rows($rows, $user),
            'suggestions' => array_map(fn (array $t) => [
                ...$t,
                'covered' => ! in_array($t['ulid'], $uncovered, true),
            ], $suggested),
            'preselect' => $subject instanceof ThreatReport && $subject->urgency === ThreatUrgency::Immediate,
            'audience' => self::audience($subject->classificationLevel()),
            'places' => $canDisseminate || $canRequest ? self::places((int) $subject->getAttribute('state_id'), (int) $subject->getAttribute('lga_id')) : null,
            'requests' => $this->requests($requests, $user, $subject),
            'can' => [
                'disseminate' => $canDisseminate,
                'request' => $canRequest,
            ],
        ];
    }

    /**
     * Props for a threat report or flashpoint page: the recipient's banner,
     * the owners' panel, and (right after creation, when the area crosses
     * a border and the record is urgent) the prompt to disseminate or to
     * request it.
     *
     * @return array{dissemination: array<string, mixed>|null, sharing: array<string, mixed>|null, disseminationPrompt: array<string, mixed>|null}
     */
    public function forPage(Model&Disseminable $subject, User $user, bool $justCreated): array
    {
        $sharing = $this->sharing($subject, $user);
        $prompt = null;

        if ($justCreated && $sharing !== null && $subject->warrantsDissemination()
            && ($sharing['can']['disseminate'] || $sharing['can']['request'])) {
            $places = array_values(array_filter($sharing['suggestions'], fn (array $t) => ! $t['covered']));

            $prompt = $places === [] ? null : [
                'mode' => $sharing['can']['disseminate'] ? 'disseminate' : 'request',
                'places' => array_column($places, 'name'),
            ];
        }

        return [
            'dissemination' => $this->banner($subject, $user),
            'sharing' => $sharing,
            'disseminationPrompt' => $prompt,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function banner(Model&Disseminable $subject, User $user): ?array
    {
        if (! $this->jurisdiction->viaGrantOnly($user, $subject)) {
            return null;
        }

        $active = RecordDissemination::query()->about($subject)->active()->receivedBy($user)
            ->with(['targetState', 'targetLga', 'sourceState', 'sourceLga'])
            ->orderByDesc('disseminated_at')
            ->get();
        $current = $active->first();

        if ($current === null) {
            return null;
        }

        $acknowledge = $active->first(fn (RecordDissemination $d) => $user->can('acknowledge', $d));
        $onward = $active->first(fn (RecordDissemination $d) => $user->can('onward', $d));

        return [
            'ulid' => $current->ulid,
            'active' => $active->pluck('ulid')->values()->all(),
            'source' => $this->notifier->source($current),
            'source_lga' => $current->sourceLga->name,
            'target' => $current->targetName(),
            'disseminated_at' => $current->disseminated_at->toIso8601String(),
            'expires_at' => $active->max('expires_at')?->toIso8601String(),
            'reason' => $current->reason,
            'acknowledged' => $acknowledge === null && $active->contains(fn (RecordDissemination $d) => $d->acknowledged_at !== null),
            'acknowledge' => $acknowledge?->ulid,
            'onward' => $onward === null ? null : [
                'ulid' => $onward->ulid,
                'lgas' => self::stateLgas((int) $user->state_id, (int) $subject->getAttribute('lga_id')),
                'audience' => self::audience($subject->classificationLevel()),
            ],
        ];
    }

    /**
     * @param  Collection<int, RecordDissemination>  $rows
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $rows, User $user): array
    {
        $names = OfficerNames::for($rows->pluck('disseminated_by')->merge($rows->pluck('acknowledged_by'))->filter());

        return array_values($rows->map(fn (RecordDissemination $d) => [
            'ulid' => $d->ulid,
            'level' => $d->target_level->value,
            'target' => $d->targetName(),
            'status' => $d->status(),
            'onward' => $d->parent_dissemination_id !== null,
            'reason' => $d->reason,
            'disseminated_at' => $d->disseminated_at->toIso8601String(),
            'disseminated_by' => $names->get($d->disseminated_by),
            'expires_at' => $d->expires_at->toIso8601String(),
            'acknowledged_at' => $d->acknowledged_at?->toIso8601String(),
            'acknowledged_by' => $d->acknowledged_by === null ? null : $names->get($d->acknowledged_by),
            'revoked_at' => $d->revoked_at?->toIso8601String(),
            'revocation_reason' => $d->revocation_reason,
            'can' => [
                'renew' => $user->can('renew', $d),
                'revoke' => $user->can('revoke', $d),
            ],
        ])->all());
    }

    /**
     * @param  Collection<int, DisseminationRequest>  $requests
     * @return list<array<string, mixed>>
     */
    public function requests(Collection $requests, User $user, Model&Disseminable $subject): array
    {
        $names = OfficerNames::for($requests->pluck('requested_by')->merge($requests->pluck('decided_by'))->filter());
        $canDecide = $user->can('decideRequest', $subject);

        return array_values($requests->map(fn (DisseminationRequest $r) => [
            'ulid' => $r->ulid,
            'status' => ['value' => $r->status->value, 'label' => $r->status->label()],
            'reason' => $r->reason,
            'targets' => $this->targetOptions($r->suggested_targets),
            'requested_by' => $names->get($r->requested_by),
            'requested_at' => $r->created_at->toIso8601String(),
            'decided_by' => $r->decided_by === null ? null : $names->get($r->decided_by),
            'decided_at' => $r->decided_at?->toIso8601String(),
            'decision_note' => $r->decision_note,
            'can_decide' => $canDecide && $r->status === DisseminationRequestStatus::Pending,
        ])->all());
    }

    /**
     * Which officers in a target can see a record at this level: the
     * dissemination form warns when some cannot (classification is a
     * hard ceiling).
     *
     * @return array{state: list<string>, lga: list<string>, hidden: array{state: list<string>, lga: list<string>}}
     */
    public static function audience(Classification $level): array
    {
        $roles = [
            'state' => [Role::StateDirector, Role::DeputyStateDirector],
            'lga' => [Role::LgaSecurityChief, Role::StateDirector, Role::DeputyStateDirector],
        ];
        $sees = fn (Role $role) => ($max = ClassificationGate::maxLevelForRole($role)) !== null && $level->isAtMost($max);
        $labels = fn (array $list) => array_values(array_map(fn (Role $role) => $role->label(), $list));

        return [
            'state' => $labels(array_filter($roles['state'], $sees)),
            'lga' => $labels(array_filter($roles['lga'], $sees)),
            'hidden' => [
                'state' => $labels(array_filter($roles['state'], fn (Role $r) => ! $sees($r))),
                'lga' => $labels(array_filter($roles['lga'], fn (Role $r) => ! $sees($r))),
            ],
        ];
    }

    /**
     * Every state and LGA a record may be disseminated to: not the record's
     * own state as a whole, nor its own LGA.
     *
     * @return list<array{ulid: string, name: string, lgas: list<array{ulid: string, name: string}>, selectable: bool}>
     */
    public static function places(int $ownStateId, int $ownLgaId): array
    {
        $lgas = Lga::query()->where('id', '<>', $ownLgaId)->orderBy('name')->get(['id', 'ulid', 'name', 'state_id'])->groupBy('state_id');

        return array_values(State::query()->orderBy('name')->get(['id', 'ulid', 'name'])
            ->map(fn (State $s) => [
                'ulid' => $s->ulid,
                'name' => $s->name,
                'selectable' => $s->id !== $ownStateId,
                'lgas' => array_values(($lgas->get($s->id) ?? collect())->map(fn (Lga $l) => ['ulid' => $l->ulid, 'name' => $l->name])->all()),
            ])->all());
    }

    /**
     * @return list<array{ulid: string, name: string}>
     */
    public static function stateLgas(int $stateId, int $exceptLgaId): array
    {
        return array_values(Lga::query()->where('state_id', $stateId)->where('id', '<>', $exceptLgaId)->orderBy('name')
            ->get(['ulid', 'name'])->map(fn (Lga $l) => ['ulid' => $l->ulid, 'name' => $l->name])->all());
    }

    /**
     * @param  list<array{level: string, ulid: string}>  $targets
     * @return list<array{level: string, ulid: string, name: string, state: string}>
     */
    private function targetOptions(array $targets): array
    {
        $ulids = array_column($targets, 'ulid');
        $states = State::query()->whereIn('ulid', $ulids)->pluck('name', 'ulid');
        $lgas = Lga::query()->whereIn('ulid', $ulids)->with('state:id,name')->get()->keyBy('ulid');
        $options = [];

        foreach ($targets as $t) {
            if ($t['level'] === DisseminationTargetLevel::State->value && $states->has($t['ulid'])) {
                $name = (string) $states->get($t['ulid']);
                $options[] = ['level' => $t['level'], 'ulid' => $t['ulid'], 'name' => "{$name} State", 'state' => $name];
            } elseif ($t['level'] === DisseminationTargetLevel::Lga->value && ($lga = $lgas->get($t['ulid'])) !== null) {
                $options[] = ['level' => $t['level'], 'ulid' => $t['ulid'], 'name' => "{$lga->name} LGA, {$lga->state->name}", 'state' => $lga->state->name];
            }
        }

        return $options;
    }
}
