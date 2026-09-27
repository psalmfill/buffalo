<?php

namespace App\Support\Jurisdiction;

use App\Enums\GrantScope;
use App\Http\Presenters\PoiPresenter;
use App\Http\Presenters\PoliticalPresenter;
use App\Models\CriminalGroupMember;
use App\Models\Person;
use App\Models\PersonAssociation;
use App\Models\PersonOfInterest;
use App\Models\PoliticalOffice;
use App\Models\RecordAccessGrant;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use LogicException;

/**
 * What a grant holder may see of a person beyond the person record itself
 * (Phase 3C, the `full` scope): POI details and status history, political
 * offices, group memberships (group name and type only) and associations
 * (the other person's name and relationship only). Nothing is linked.
 *
 * SANCTIONED SCOPE BYPASS (inside App\Support\Jurisdiction, through the
 * audited runAsSystem()): these records belong to the owning jurisdiction
 * and are not visible to the holder through the scopes; a grant never makes
 * a POI record, office or criminal group itself visible. Everything shown
 * is still filtered by the HOLDER's clearance (a grant never overrides
 * classification): groups and associated persons above it are left out.
 */
final class GrantedPersonView
{
    public function __construct(
        private readonly JurisdictionContext $context,
        private readonly ClassificationGate $classification,
    ) {}

    /**
     * @return array{poi: array<string, mixed>|null, politicalOffices: array{current: list<array<string, mixed>>, past: list<array<string, mixed>>}, groupMemberships: list<array<string, mixed>>, associations: list<array<string, mixed>>}
     */
    public function full(Person $person, User $holder, RecordAccessGrant $grant): array
    {
        if ($grant->scope !== GrantScope::Full || ! $grant->isActive() || $grant->user_id !== $holder->id || $grant->subject_id !== $person->id) {
            throw new LogicException('A full, active grant of this user for this person is required.');
        }

        return $this->context->runAsSystem('Show a person under a full cross-jurisdiction grant', function () use ($person, $holder): array {
            $poi = PersonOfInterest::query()->withCoordinates()->with(['lga', 'reviewer', 'person.lga', 'person.roles'])->where('person_id', $person->id)->first();

            $offices = PoliticalOffice::query()
                ->where('person_id', $person->id)
                ->with(['state', 'senatorialDistrict', 'lga', 'ward'])
                ->orderByDesc('started_on')
                ->get();

            $memberships = array_values(CriminalGroupMember::query()
                ->where('person_id', $person->id)
                ->with('group')
                ->get()
                ->filter(fn (CriminalGroupMember $m) => $this->classification->canSee($holder, $m->group))
                ->map(fn (CriminalGroupMember $m) => [
                    'ulid' => $m->ulid,
                    'group' => ['name' => $m->group->name, 'type' => ['value' => $m->group->type->value, 'label' => $m->group->type->label()]],
                    'role_in_group' => $m->role_in_group,
                    'membership_status' => ['value' => $m->membership_status->value, 'label' => $m->membership_status->label()],
                    'since' => $m->since?->toDateString(),
                ])->all());

            $links = PersonAssociation::query()
                ->where(fn ($q) => $q->where('person_id', $person->id)->orWhere('associated_person_id', $person->id))
                ->get();
            $others = Person::query()->whereIn('id', $links->map(fn (PersonAssociation $l) => $l->otherPersonId($person)))
                ->get(['id', 'first_name', 'last_name', 'other_names', 'classification', 'created_by'])
                ->keyBy('id');

            $associations = [];

            foreach ($links as $link) {
                $other = $others->get($link->otherPersonId($person));

                if ($other instanceof Person && $this->classification->canSee($holder, $other)) {
                    $associations[] = [
                        'ulid' => $link->ulid,
                        'name' => $other->name,
                        'relationship' => ['value' => $link->relationshipFor($person)->value, 'label' => $link->relationshipFor($person)->label()],
                    ];
                }
            }

            $present = fn (PoliticalOffice $o) => PoliticalPresenter::office($o, withPerson: false);

            return [
                'poi' => $poi === null ? null : [
                    ...array_diff_key(PoiPresenter::detail($poi), array_flip(['ulid', 'person', 'lga'])),
                    'can' => ['update' => false, 'clear' => false, 'delete' => false],
                ],
                'politicalOffices' => [
                    'current' => array_values($offices->filter(fn (PoliticalOffice $o) => $o->isCurrent())->map($present)->all()),
                    'past' => array_values($offices->reject(fn (PoliticalOffice $o) => $o->isCurrent())->map($present)->all()),
                ],
                'groupMemberships' => $memberships,
                'associations' => $associations,
            ];
        });
    }
}
