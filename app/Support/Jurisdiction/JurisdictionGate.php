<?php

namespace App\Support\Jurisdiction;

use App\Models\User;
use App\Support\Jurisdiction\Contracts\AccessGrantResolver;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use App\Support\Jurisdiction\Contracts\LgaVisibilityRule;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Single-record jurisdiction check for policies. Applies exactly the same
 * rules as JurisdictionScope (both use JurisdictionRules), so policies check
 * jurisdiction even when the scope has already filtered: defence in depth.
 *
 * It evaluates the given user explicitly and ignores the current context.
 */
final class JurisdictionGate
{
    public function __construct(
        private readonly JurisdictionRules $rules,
        private readonly AccessGrantResolver $grants,
    ) {}

    public function canAccess(User $user, Model $record): bool
    {
        if (! $record instanceof JurisdictionScoped) {
            throw new InvalidArgumentException($record::class.' is not jurisdiction-scoped.');
        }

        if ($this->canAccessOwn($user, $record)) {
            return true;
        }

        return ! $user->isAccessBlocked() && $this->grants->hasGrant($user, $record);
    }

    /**
     * Within the user's OWN jurisdiction, ignoring access grants. Grants
     * are read-only (CLAUDE.md section 4 rule 8): every write ability must
     * use this, not canAccess().
     */
    public function canAccessOwn(User $user, Model $record): bool
    {
        if (! $record instanceof JurisdictionScoped) {
            throw new InvalidArgumentException($record::class.' is not jurisdiction-scoped.');
        }

        return $this->withinJurisdiction($this->rules->restrictionFor($user, $record->isVisibleToSuperAdmin()), $record);
    }

    /** Access comes from a grant only (not the user's own jurisdiction). */
    public function viaGrantOnly(User $user, Model $record): bool
    {
        return $record instanceof JurisdictionScoped
            && ! $this->canAccessOwn($user, $record)
            && ! $user->isAccessBlocked()
            && $this->grants->hasGrant($user, $record);
    }

    private function withinJurisdiction(JurisdictionRestriction $restriction, Model&JurisdictionScoped $record): bool
    {
        return match ($restriction->type) {
            RestrictionType::Everything => true,
            RestrictionType::Nothing => false,
            RestrictionType::States => in_array($this->intAttribute($record, $record->jurisdictionStateColumn()), $restriction->stateIds, true),
            RestrictionType::Lga => $record instanceof LgaVisibilityRule
                ? $restriction->lgaId !== null && $record->visibleToLga($restriction->lgaId)
                : $record->jurisdictionLgaColumn() !== null
                && $restriction->lgaId !== null
                && $this->intAttribute($record, $record->jurisdictionLgaColumn()) === $restriction->lgaId,
        };
    }

    private function intAttribute(Model $record, string $column): ?int
    {
        $value = $record->getAttribute($column);

        return is_numeric($value) ? (int) $value : null;
    }
}
