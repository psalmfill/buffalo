<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Models\CriminalGroup;
use App\Models\CriminalGroupMember;
use App\Models\Person;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use Illuminate\Database\Eloquent\Builder;

/**
 * A criminal group's members: the group page's Members tab (Phase 3D list,
 * 11E export). SENSITIVE. Only memberships whose person the requester can
 * see, exactly as on the page; group, person name, role in the group,
 * membership status and since. No encrypted fields, photos or notes.
 */
final class CriminalGroupMembersExporter extends ListExporter
{
    public function key(): string
    {
        return 'criminal_group_members';
    }

    public function label(): string
    {
        return 'Criminal group members';
    }

    public function page(): string
    {
        return 'criminal-groups.show#members';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', CriminalGroup::class);
    }

    public function sensitive(): bool
    {
        return true;
    }

    public function rules(User $user): array
    {
        return ['group' => ['required', 'string', 'size:26']];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<CriminalGroupMember>
     */
    public function query(User $user, array $filters): Builder
    {
        $group = CriminalGroup::query()->where('ulid', (string) ($filters['group'] ?? ''))->first();

        return CriminalGroupMember::query()
            ->where('criminal_group_id', $group->id ?? 0)
            ->withVisiblePerson()
            ->orderByRaw("CASE membership_status WHEN 'confirmed' THEN 1 WHEN 'suspected' THEN 2 ELSE 3 END")
            ->orderBy('criminal_group_members.id');
    }

    /** The group's level and its members' persons' levels. */
    public function levels(User $user, array $filters): iterable
    {
        $members = $this->query($user, $filters)->toBase()->cloneWithout(['orders', 'columns'])->cloneWithoutBindings(['select', 'order']);

        return [
            ...CriminalGroup::query()->where('ulid', (string) ($filters['group'] ?? ''))->pluck('classification')->all(),
            ...Person::query()->whereIn('persons.id', (clone $members)->select('criminal_group_members.person_id'))->distinct()->pluck('persons.classification')->all(),
        ];
    }

    public function with(): array
    {
        return ['group', 'person'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('group', 'Group', fn (CriminalGroupMember $m) => $m->group->name),
            ListColumn::make('name', 'Person', fn (CriminalGroupMember $m) => $m->person?->name),
            ListColumn::make('role', 'Role in group', fn (CriminalGroupMember $m) => $m->role_in_group),
            ListColumn::make('status', 'Membership status', fn (CriminalGroupMember $m) => $m->membership_status->label()),
            ListColumn::make('since', 'Since', fn (CriminalGroupMember $m) => self::date($m->since)),
        ];
    }

    public function defaultColumns(): array
    {
        return ['group', 'name', 'role', 'status', 'since'];
    }
}
