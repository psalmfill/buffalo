<?php

namespace App\Support\CrossJurisdiction;

use App\Enums\Classification;
use App\Enums\MatchContext;
use App\Enums\MatchType;
use App\Enums\PoiStatus;
use App\Enums\Role;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\RecordMatchEvent;
use App\Models\State;
use App\Models\User;
use App\Notifications\MatchSoftLimitExceeded;
use App\Notifications\SuppressedMatchAlert;
use App\Support\Audit\AuditLog;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Notifications\NotifyRole;
use App\Support\Persons\PersonSearch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Cross-jurisdiction person matching (Phase 3C, CLAUDE.md section 4 rule 8).
 *
 * THE ONLY CLASS outside App\Support\Jurisdiction that queries persons
 * across jurisdictions (architecture test), always through
 * JurisdictionContext::runAsSystem() with a reason, which is audited.
 *
 * Match types (thresholds in config/buffalo.php, cross_jurisdiction):
 * - exact: same NIN or phone (blind index);
 * - strong: name or alias similarity >= strong_similarity AND same date
 *   of birth (blind index);
 * - possible: name or alias similarity >= possible_similarity. Never shown
 *   across jurisdictions.
 *
 * Filtering, in this order: soft-deleted persons are never candidates;
 * records the user can see go to `visible`; national users (DG, DDG) get
 * no hits; records above the user's clearance are suppressed (recorded,
 * owner or national oversight notified, at most once per officer, record
 * and day); only exact and strong remain; strongest per record, at most
 * max_hits. Daily soft and hard limits apply to hits shown.
 */
final class PersonMatchService
{
    public function __construct(
        private readonly JurisdictionContext $context,
        private readonly ClassificationGate $classification,
        private readonly NotifyRole $notify,
    ) {}

    /**
     * @param  bool  $record  false: only find out whether hits exist (no
     *                        events, tokens, notifications or limits), e.g.
     *                        to re-check on save
     */
    public function match(MatchCriteria $criteria, User $user, MatchContext $context, bool $record = true): MatchResult
    {
        if ($criteria->isEmpty()) {
            return new MatchResult(new Collection, []);
        }

        /** @var array<int, array{type: MatchType, person: Person, poi: bool}> $candidates */
        $candidates = $this->context->runAsSystem(
            "Cross-jurisdiction person match ({$context->value})",
            fn (): array => $this->candidates($criteria),
        );

        if ($candidates === []) {
            return new MatchResult(new Collection, []);
        }

        // Visible to the user (their scopes: jurisdiction, grants, classification).
        $visible = $this->context->actingAs($user, fn () => Person::query()->with(['lga', 'roles'])->whereIn('persons.id', array_keys($candidates))->get());
        $remaining = array_diff_key($candidates, array_flip($visible->modelKeys()));

        if ($remaining === [] || $this->isNational($user)) {
            return new MatchResult($visible, []);
        }

        $showable = [];

        foreach ($remaining as $personId => $candidate) {
            if (! $this->classification->canSee($user, $candidate['person'])) {
                if ($record) {
                    $this->suppress($user, $candidate['person'], $candidate['type'], $context);
                }

                continue;
            }

            if ($candidate['type']->showableAcrossJurisdictions()) {
                $showable[$personId] = $candidate;
            }
        }

        uasort($showable, fn (array $a, array $b) => $b['type']->rank() <=> $a['type']->rank());
        $showable = array_slice($showable, 0, (int) config('buffalo.cross_jurisdiction.max_hits', 10), true);

        if (! $record) {
            return new MatchResult($visible, array_map(fn (array $c) => new Hit('', '', '', $c['type']), array_values($showable)));
        }

        return $this->withinLimits($user, $visible, $showable, $context);
    }

    /**
     * Resolves a hit token for a request (the client never sends a record
     * identifier). The record must still exist and be within the user's
     * clearance.
     *
     * @return array{person: Person, match_type: MatchType, match_event_id: int}
     *
     * @throws InvalidHitToken
     */
    public function resolveHit(string $token, User $user): array
    {
        $data = HitToken::decode($token, $user);

        $person = $this->context->runAsSystem(
            'Resolve a cross-jurisdiction hit for an access request',
            fn (): ?Person => Person::query()->find($data['person_id'], ['id', 'ulid', 'state_id', 'lga_id', 'classification', 'created_by']),
        );

        if ($person === null || ! $this->classification->canSee($user, $person)) {
            throw new InvalidHitToken('The record is no longer available.');
        }

        return ['person' => $person, 'match_type' => $data['match_type'], 'match_event_id' => $data['match_event_id']];
    }

    /**
     * Every candidate (system context), strongest type per record.
     *
     * @return array<int, array{type: MatchType, person: Person, poi: bool}>
     */
    private function candidates(MatchCriteria $criteria): array
    {
        $types = [];

        if ($criteria->nin !== null || $criteria->phone !== null) {
            $exact = Person::query()->where(function ($q) use ($criteria): void {
                if ($criteria->nin !== null) {
                    $q->orWhereBlind('nin', 'nin_bidx', $criteria->nin);
                }

                if ($criteria->phone !== null) {
                    $q->orWhereBlind('phone', 'phone_bidx', $criteria->phone);
                }
            })->pluck('id');

            foreach ($exact as $id) {
                $types[(int) $id] = MatchType::Exact;
            }
        }

        $scores = $this->nameScores($criteria);

        if ($scores !== []) {
            $sameDob = $criteria->dateOfBirth === null ? [] : Person::query()
                ->whereIn('persons.id', array_keys($scores))
                ->whereBlind('date_of_birth', 'dob_bidx', $criteria->dateOfBirth)
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            $strong = (float) config('buffalo.cross_jurisdiction.strong_similarity', 0.75);

            foreach ($scores as $id => $score) {
                $type = $score >= $strong && in_array($id, $sameDob, true) ? MatchType::Strong : MatchType::Possible;

                if (! isset($types[$id]) || $types[$id]->rank() < $type->rank()) {
                    $types[$id] = $type;
                }
            }
        }

        if ($types === []) {
            return [];
        }

        // Only what the filters need: place and clearance, never content.
        $persons = Person::query()->whereIn('persons.id', array_keys($types))->get(['id', 'state_id', 'lga_id', 'classification', 'created_by'])->keyBy('id');
        $pois = PersonOfInterest::query()->whereIn('person_id', array_keys($types))
            ->where('status', '<>', PoiStatus::Cleared->value)
            ->pluck('person_id')->map(fn ($id) => (int) $id)->all();

        $candidates = [];

        foreach ($types as $id => $type) {
            $person = $persons->get($id);

            if ($person instanceof Person) {
                $candidates[$id] = ['type' => $type, 'person' => $person, 'poi' => in_array($id, $pois, true)];
            }
        }

        return $candidates;
    }

    /**
     * Best name/alias similarity per person, >= possible_similarity, using
     * the trigram indexes (`%` with a transaction-local threshold).
     *
     * @return array<int, float>
     */
    private function nameScores(MatchCriteria $criteria): array
    {
        $possible = (float) config('buffalo.cross_jurisdiction.possible_similarity', 0.6);
        $scores = [];

        foreach ($criteria->names() as $name) {
            $rows = PersonSearch::withThresholds($possible, 1.0, fn () => PersonSearch::selectNameScore(
                PersonSearch::whereNameMatches(Person::query(), $name, partial: false),
                $name,
                partial: false,
            )->get(['persons.id']));

            foreach ($rows as $row) {
                $score = (float) $row->getAttribute('name_score');
                $id = (int) $row->getKey();

                if ($score >= $possible && $score > ($scores[$id] ?? 0.0)) {
                    $scores[$id] = $score;
                }
            }
        }

        return $scores;
    }

    /**
     * Applies the daily limits, records a match event per hit and issues
     * the hit tokens.
     *
     * @param  Collection<int, Person>  $visible
     * @param  array<int, array{type: MatchType, person: Person, poi: bool}>  $showable
     */
    private function withinLimits(User $user, Collection $visible, array $showable, MatchContext $context): MatchResult
    {
        if ($showable === []) {
            return new MatchResult($visible, []);
        }

        $today = CarbonImmutable::today();
        $shownToday = RecordMatchEvent::query()->where('user_id', $user->id)->where('suppressed', false)->where('created_at', '>=', $today)->count();
        $hard = (int) config('buffalo.cross_jurisdiction.hard_daily_limit', 50);
        $soft = (int) config('buffalo.cross_jurisdiction.soft_daily_limit', 20);

        if ($shownToday >= $hard) {
            if (Cache::add("buffalo:match-hard-limit:{$user->id}:{$today->toDateString()}", true, $today->endOfDay())) {
                AuditLog::event('match_hard_limit_reached', 'Daily cross-jurisdiction hit limit reached', $user, ['limit' => $hard]);
            }

            return new MatchResult($visible, [], limitReached: true);
        }

        $showable = array_slice($showable, 0, $hard - $shownToday, true);
        $states = State::query()->whereIn('id', array_map(fn (array $c) => $c['person']->state_id, $showable))->pluck('name', 'id');
        $hits = [];

        foreach ($showable as $personId => $candidate) {
            $event = $this->event($user, $candidate['person'], $candidate['type'], $context, suppressed: false);
            $hits[] = new Hit(
                HitToken::issue($personId, $user, $candidate['type'], $event->id),
                (string) $states->get($candidate['person']->state_id),
                $candidate['poi'] ? Hit::CATEGORY_POI : Hit::CATEGORY_PERSON,
                $candidate['type'],
            );
        }

        if ($shownToday + count($hits) > $soft && Cache::add("buffalo:match-soft-limit:{$user->id}:{$today->toDateString()}", true, $today->endOfDay())) {
            $notice = new MatchSoftLimitExceeded($user->name, $user->state !== null ? $user->state->name.' State' : 'national', $soft);
            $this->notify->send($notice, Role::DirectorGeneral);
            $this->notify->send($notice, Role::DeputyDirectorGeneral, $user->state);
        }

        return new MatchResult($visible, $hits);
    }

    private function suppress(User $user, Person $person, MatchType $type, MatchContext $context): void
    {
        $alreadyToday = RecordMatchEvent::query()
            ->where('user_id', $user->id)
            ->where('subject_type', $person->getMorphClass())
            ->where('subject_id', $person->id)
            ->where('suppressed', true)
            ->where('created_at', '>=', CarbonImmutable::today())
            ->exists();

        $this->event($user, $person, $type, $context, suppressed: true);

        if ($alreadyToday) {
            return;
        }

        $level = $person->classificationLevel();
        $alert = new SuppressedMatchAlert($user->name, $user->state !== null ? $user->state->name.' State' : 'national', $level->value);
        $state = State::query()->find($person->state_id);

        if ($level === Classification::Secret) {
            $this->notify->send($alert, Role::DirectorGeneral);
            $this->notify->send($alert, Role::DeputyDirectorGeneral);
        } else {
            $this->notify->send($alert, Role::StateDirector, $state);
        }
    }

    private function event(User $user, Person $person, MatchType $type, MatchContext $context, bool $suppressed): RecordMatchEvent
    {
        return RecordMatchEvent::query()->create([
            'user_id' => $user->id,
            'subject_type' => $person->getMorphClass(),
            'subject_id' => $person->id,
            'owning_state_id' => $person->state_id,
            'owning_lga_id' => $person->lga_id,
            'match_type' => $type,
            'context' => $context,
            'suppressed' => $suppressed,
        ]);
    }

    /** DG and DDGs see everything within classification: never hits. */
    private function isNational(User $user): bool
    {
        return in_array($user->getRole(), [Role::DirectorGeneral, Role::DeputyDirectorGeneral], true);
    }
}
