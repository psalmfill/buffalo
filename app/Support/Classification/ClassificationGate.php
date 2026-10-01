<?php

namespace App\Support\Classification;

use App\Enums\Classification;
use App\Enums\Role;
use App\Models\User;
use App\Support\Classification\Contracts\Classified;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The single source of truth for classification (CLAUDE.md section 4a),
 * used by ClassificationScope (queries) and by policies (single records).
 * It narrows access within jurisdiction and never widens it: callers always
 * check jurisdiction as well.
 *
 * | Role                     | Highest level seen/assigned |
 * |--------------------------|-----------------------------|
 * | DG, DDG, State Director  | secret                      |
 * | Deputy State Director    | confidential                |
 * | LGA Security Chief       | restricted                  |
 * | super admin, no role     | none (no intelligence)      |
 *
 * A record's creator always sees it (even above their level). Blocked
 * accounts see nothing.
 */
final class ClassificationGate
{
    public function maxLevelFor(User $user): ?Classification
    {
        if ($user->isAccessBlocked()) {
            return null;
        }

        return self::maxLevelForRole($user->getRole());
    }

    /** The highest level a role sees (ignoring the creator rule and blocked accounts). */
    public static function maxLevelForRole(?Role $role): ?Classification
    {
        return match ($role) {
            Role::DirectorGeneral, Role::DeputyDirectorGeneral, Role::StateDirector => Classification::Secret,
            Role::DeputyStateDirector => Classification::Confidential,
            Role::LgaSecurityChief => Classification::Restricted,
            Role::SuperAdmin, null => null,
        };
    }

    public function canSee(User $user, Model $record): bool
    {
        if (! $record instanceof Classified) {
            throw new InvalidArgumentException($record::class.' is not classified.');
        }

        $max = $this->maxLevelFor($user);

        if ($max === null) {
            return false;
        }

        return $record->classificationLevel()->isAtMost($max)
            || (int) $record->getAttribute('created_by') === $user->id;
    }

    /**
     * Whether the user may create a record at, or move a record to, $level:
     * never above what they could see.
     */
    public function canAssign(User $user, Classification $level): bool
    {
        $max = $this->maxLevelFor($user);

        return $max !== null && $level->isAtMost($max);
    }

    /**
     * @return list<Classification>
     */
    public function assignableLevels(User $user): array
    {
        $max = $this->maxLevelFor($user);

        return $max === null ? [] : Classification::upTo($max);
    }
}
