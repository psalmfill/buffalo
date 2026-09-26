<?php

namespace App\Support\Political;

use App\Enums\PersonRoleType;
use App\Enums\PoliticalOfficeType;
use App\Enums\Role;
use App\Http\Presenters\PersonPresenter;
use App\Http\Presenters\PoliticalPresenter;
use App\Models\Community;
use App\Models\Lga;
use App\Models\OfficerPosting;
use App\Models\Person;
use App\Models\PoliticalOffice;
use App\Models\ReligiousInstitution;
use App\Models\SenatorialDistrict;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who leads an LGA or a senatorial district (Phase 3B): current office
 * holders, traditional rulers, religious and community leaders.
 *
 * Every query goes through the scopes, so each viewer sees only the
 * offices and persons they may see (an LGA chief, for example, sees their
 * LGA's chairman but not a neighbouring LGA's).
 */
final class Leadership
{
    private const OFFICE_RELATIONS = ['person', 'state', 'senatorialDistrict', 'lga', 'ward'];

    /**
     * The current LGA security chief (officer posting), if visible.
     *
     * @return array{ulid: string, name: string}|null
     */
    public function chief(Lga $lga): ?array
    {
        $user = OfficerPosting::query()
            ->with('user')
            ->where('role', Role::LgaSecurityChief->value)
            ->where('lga_id', $lga->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first()
            ?->user;

        return $user === null ? null : ['ulid' => $user->ulid, 'name' => $user->name];
    }

    /**
     * The LGA page's Leadership tab.
     *
     * @return array<string, mixed>
     */
    public function forLga(Lga $lga): array
    {
        $offices = PoliticalOffice::query()
            ->current()
            ->with(self::OFFICE_RELATIONS)
            ->where('lga_id', $lga->id)
            ->whereIn('office', [PoliticalOfficeType::LgaChairman->value, PoliticalOfficeType::LgaViceChairman->value, PoliticalOfficeType::Councillor->value])
            ->orderBy('started_on')
            ->get();

        $present = fn (PoliticalOfficeType $type) => $offices->filter(fn (PoliticalOffice $o) => $o->office === $type)
            ->map(fn (PoliticalOffice $o) => PoliticalPresenter::office($o))->values()->all();

        return [
            'chairman' => $present(PoliticalOfficeType::LgaChairman),
            'viceChairman' => $present(PoliticalOfficeType::LgaViceChairman),
            'councillors' => $offices->filter(fn (PoliticalOffice $o) => $o->office === PoliticalOfficeType::Councillor)
                ->sortBy(fn (PoliticalOffice $o) => $o->ward?->name)
                ->map(fn (PoliticalOffice $o) => PoliticalPresenter::office($o))->values()->all(),
            'traditionalRulers' => Community::query()
                ->where('lga_id', $lga->id)
                ->whereHas('traditionalRuler')
                ->with('traditionalRuler')
                ->orderBy('name')
                ->get()
                ->map(fn (Community $c) => [
                    'community' => ['ulid' => $c->ulid, 'name' => $c->name],
                    'person' => ['ulid' => $c->traditionalRuler?->ulid, 'name' => $c->traditionalRuler?->name],
                ])->all(),
            'religiousLeaders' => ReligiousInstitution::query()
                ->where('lga_id', $lga->id)
                ->whereHas('leader')
                ->with(['leader', 'religion'])
                ->orderBy('name')
                ->get()
                ->map(fn (ReligiousInstitution $i) => [
                    'institution' => ['ulid' => $i->ulid, 'name' => $i->name, 'religion' => $i->religion->name],
                    'person' => ['ulid' => $i->leader?->ulid, 'name' => $i->leader?->name],
                ])->all(),
            'communityLeaders' => $this->personsWithRoles([PersonRoleType::CommunityLeader], [$lga->id], 100),
        ];
    }

    /**
     * A senatorial district page.
     *
     * @return array<string, mixed>
     */
    public function forDistrict(SenatorialDistrict $district): array
    {
        $lgas = Lga::query()->where('senatorial_district_id', $district->id)->active()->orderBy('name')->get();
        /** @var list<int> $lgaIds */
        $lgaIds = array_values(array_map('intval', $lgas->modelKeys()));

        $connected = fn (Builder $q) => $q->where(fn (Builder $w) => $w
            ->where('senatorial_district_id', $district->id)
            ->orWhereIn('lga_id', $lgaIds));

        $current = PoliticalOffice::query()->current()->with(self::OFFICE_RELATIONS)
            ->where($connected)
            ->whereIn('office', [
                PoliticalOfficeType::Senator->value,
                PoliticalOfficeType::HouseOfRepsMember->value,
                PoliticalOfficeType::StateAssemblyMember->value,
                PoliticalOfficeType::LgaChairman->value,
            ])
            ->orderByRaw("CASE office WHEN 'senator' THEN 1 WHEN 'house_of_reps_member' THEN 2 WHEN 'state_assembly_member' THEN 3 ELSE 4 END")
            ->orderBy('started_on')
            ->get();

        $chairmen = $current->filter(fn (PoliticalOffice $o) => $o->office === PoliticalOfficeType::LgaChairman)->groupBy('lga_id');

        return [
            'lgas' => $lgas->map(fn (Lga $lga) => [
                'ulid' => $lga->ulid,
                'name' => $lga->name,
                'chief' => $this->chief($lga),
                'chairman' => $chairmen->get($lga->id)?->map(fn (PoliticalOffice $o) => ['ulid' => $o->person?->ulid, 'name' => $o->person?->name])->values()->all() ?? [],
            ])->all(),
            'currentHolders' => $current->map(fn (PoliticalOffice $o) => PoliticalPresenter::office($o))->values()->all(),
            'prominentPersons' => $this->personsWithRoles(
                [PersonRoleType::PoliticalLeader, PersonRoleType::TraditionalRuler, PersonRoleType::ReligiousLeader, PersonRoleType::CommunityLeader],
                $lgaIds,
                200,
            ),
            'pastHolders' => PoliticalOffice::query()->past()->with(self::OFFICE_RELATIONS)
                ->where($connected)
                ->orderByDesc('ended_on')
                ->limit(100)
                ->get()
                ->map(fn (PoliticalOffice $o) => PoliticalPresenter::office($o))->values()->all(),
        ];
    }

    /**
     * Visible persons of these LGAs holding any of these roles (active).
     *
     * @param  list<PersonRoleType>  $roles
     * @param  list<int>  $lgaIds
     * @return list<array<string, mixed>>
     */
    private function personsWithRoles(array $roles, array $lgaIds, int $limit): array
    {
        return array_values(Person::query()
            ->with(['lga', 'roles'])
            ->whereIn('persons.lga_id', $lgaIds)
            ->whereHas('roles', fn (Builder $q) => $q->where('is_active', true)->whereIn('role', array_map(fn (PersonRoleType $r) => $r->value, $roles)))
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit($limit)
            ->get()
            ->map(fn (Person $p) => PersonPresenter::row($p))
            ->all());
    }
}
