<?php

namespace App\Support\Political;

use App\Enums\PoliticalOfficeType;
use App\Enums\Role;
use App\Http\Presenters\PersonPresenter;
use App\Http\Presenters\PoliticalPresenter;
use App\Models\Community;
use App\Models\Lga;
use App\Models\OfficerPosting;
use App\Models\Person;
use App\Models\PersonRoleType;
use App\Models\PoliticalOffice;
use App\Models\ReligiousInstitution;
use App\Models\SenatorialDistrict;
use App\Support\Exports\Lists\Exporters\PoliticalOfficesExporter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who leads an LGA or a senatorial district (Phase 3B): current office
 * holders, traditional rulers, religious leaders, and community-level
 * leaders grouped by role type (Phase 11A.1).
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
        // The same query as the export (Phase 11E).
        $offices = PoliticalOfficesExporter::currentForLga($lga)
            ->with(self::OFFICE_RELATIONS)
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
            // Community-level leaders, grouped by role type (Phase 11A.1),
            // including types added by administrators.
            'leadersByRole' => $this->groupedByRole(
                PersonRoleType::query()->whereNotIn('code', PersonRoleType::NOT_COMMUNITY_LEVEL),
                [$lga->id],
                100,
            ),
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
            // Persons holding any leadership role, grouped by role type.
            'prominentByRole' => $this->groupedByRole(
                PersonRoleType::query()->where('code', '!=', PersonRoleType::PERSON_OF_INTEREST),
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
     * Visible persons of these LGAs holding each role type (active roles),
     * one group per type that has anyone (types in order). A deactivated
     * type still lists those who hold it.
     *
     * @param  Builder<PersonRoleType>  $types
     * @param  list<int>  $lgaIds
     * @return list<array{type: array{value: string, label: string}, persons: list<array<string, mixed>>}>
     */
    private function groupedByRole(Builder $types, array $lgaIds, int $limit): array
    {
        $groups = [];

        foreach ($types->orderBy('sort_order')->orderBy('name')->get() as $type) {
            $persons = Person::query()
                ->with(['lga', 'roles'])
                ->whereIn('persons.lga_id', $lgaIds)
                ->whereHas('roles', fn (Builder $q) => $q->where('is_active', true)->where('person_role_type_id', $type->id))
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->limit($limit)
                ->get();

            if ($persons->isNotEmpty()) {
                $groups[] = ['type' => $type->option(), 'persons' => array_values($persons->map(fn (Person $p) => PersonPresenter::row($p))->all())];
            }
        }

        return $groups;
    }
}
