<?php

namespace App\Support\Help;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The in-app manuals (Phase 9B.4): Markdown files in docs/manuals, bundled
 * into the image. Each file starts with front matter:
 *
 *     ---
 *     title: Reporting an incident
 *     summary: One line for the index.
 *     audience: intelligence        (all | intelligence | super_admin | role)
 *     role: state_director          (role manuals and cards only)
 *     order: 10
 *     ---
 *
 * Visibility: the getting-started guide for everyone; role manuals for the
 * intelligence roles (each user's own first), the super admin manual for
 * super admins only; task guides for the intelligence roles (and the ones
 * marked `all` for everyone).
 */
final class ManualLibrary
{
    public const KINDS = ['start' => '', 'roles' => 'roles', 'guides' => 'guides', 'cards' => 'cards'];

    /** @var list<Manual>|null */
    private ?array $manuals = null;

    public function __construct(private readonly string $root) {}

    public static function make(): self
    {
        return new self((string) config('buffalo.help.path'));
    }

    /**
     * @return list<Manual>
     */
    public function all(): array
    {
        if ($this->manuals !== null) {
            return $this->manuals;
        }

        $manuals = [];
        foreach (self::KINDS as $kind => $directory) {
            foreach (glob(rtrim($this->root.'/'.$directory, '/').'/*.md') ?: [] as $path) {
                $manuals[] = Manual::fromFile($kind, $path);
            }
        }

        usort($manuals, fn (Manual $a, Manual $b) => [$a->order, $a->title] <=> [$b->order, $b->title]);

        return $this->manuals = $manuals;
    }

    /**
     * Everything this user may read, the user's own role manual first.
     *
     * @return list<Manual>
     */
    public function visibleTo(User $user): array
    {
        $role = $user->getRole();
        $visible = array_values(array_filter($this->all(), fn (Manual $manual) => $this->canRead($user, $manual)));

        usort($visible, fn (Manual $a, Manual $b) => ($b->role === $role) <=> ($a->role === $role));

        return $visible;
    }

    public function canRead(User $user, Manual $manual): bool
    {
        $role = $user->getRole();

        if ($role === null) {
            return false;
        }

        $intelligence = $role !== Role::SuperAdmin;

        return match ($manual->audience) {
            'all' => true,
            'intelligence' => $intelligence,
            'super_admin' => $role === Role::SuperAdmin,
            // Role manuals and cards: intelligence roles read all of the
            // intelligence roles' manuals; the super admin reads only theirs.
            'role' => $manual->role === Role::SuperAdmin ? $role === Role::SuperAdmin : $intelligence,
            default => false,
        };
    }

    public function find(string $kind, string $slug): ?Manual
    {
        foreach ($this->all() as $manual) {
            if ($manual->kind === $kind && $manual->slug === Str::lower($slug)) {
                return $manual;
            }
        }

        return null;
    }

    public function card(Role $role): ?Manual
    {
        foreach ($this->all() as $manual) {
            if ($manual->kind === 'cards' && $manual->role === $role) {
                return $manual;
            }
        }

        return null;
    }
}
