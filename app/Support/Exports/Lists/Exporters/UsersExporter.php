<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\Role;
use App\Http\Presenters\UserPresenter;
use App\Models\State;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Users\UserStatusFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * User accounts (Phase 1 list, 11E export): super admins only, with
 * step-up for every export. No credentials, two-factor secrets or recovery
 * codes, ever.
 */
final class UsersExporter extends ListExporter
{
    public const SORTABLE = ['name', 'email', 'service_number', 'rank', 'last_login_at', 'created_at'];

    public function key(): string
    {
        return 'users';
    }

    public function label(): string
    {
        return 'User accounts';
    }

    public function page(): string
    {
        return 'users.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', User::class);
    }

    public function forSuperAdmin(): bool
    {
        return true;
    }

    public function alwaysStepUp(): bool
    {
        return true;
    }

    public function rules(User $user): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', Rule::enum(Role::class)],
            'state' => ['nullable', 'string', 'max:26'],
            'status' => ['nullable', Rule::in(UserStatusFilter::STATUSES)],
            'sort' => ['nullable', Rule::in(self::SORTABLE)],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<User>
     */
    public function query(User $user, array $filters): Builder
    {
        return User::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search).'%';
                $query->where(fn (Builder $q) => $q->where('name', 'ilike', $term)
                    ->orWhere('email', 'ilike', $term)
                    ->orWhere('service_number', 'ilike', $term));
            })
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query->role($role))
            ->when($filters['state'] ?? null, fn (Builder $query, string $state) => $query->whereIn('state_id', State::query()->where('ulid', $state)->select('id')))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => UserStatusFilter::apply($query, $status))
            ->orderBy((string) ($filters['sort'] ?? 'name'), ($filters['direction'] ?? 'asc') === 'desc' ? 'desc' : 'asc')
            ->orderBy('id');
    }

    public function with(): array
    {
        return ['roles', 'state', 'lga', 'zones'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'Name', fn (User $u) => $u->name),
            ListColumn::make('email', 'Email', fn (User $u) => $u->email),
            ListColumn::make('service_number', 'Service number', fn (User $u) => $u->service_number),
            ListColumn::make('rank', 'Rank', fn (User $u) => $u->rank),
            ListColumn::make('role', 'Role', fn (User $u) => $u->getRole()?->label()),
            ListColumn::make('jurisdiction', 'Jurisdiction', fn (User $u) => $u->jurisdictionLabel()),
            ListColumn::make('status', 'Status', fn (User $u) => ($statuses = UserPresenter::statuses($u)) === [] ? 'Active' : implode(', ', array_map(fn (string $s) => str_replace('_', ' ', $s), $statuses))),
            ListColumn::make('last_login_at', 'Last sign-in', fn (User $u) => self::at($u->last_login_at)),
            ListColumn::make('created_at', 'Created', fn (User $u) => self::at($u->created_at)),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'email', 'service_number', 'rank', 'role', 'jurisdiction', 'status', 'last_login_at'];
    }
}
