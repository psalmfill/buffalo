<?php

namespace App\Support\Jurisdiction;

use App\Models\User;
use App\Support\Jurisdiction\Contracts\AccessGrantResolver;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use App\Support\Jurisdiction\Contracts\LgaVisibilityRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use LogicException;

/**
 * Global scope applied by App\Models\Concerns\HasJurisdiction.
 *
 * | Context                         | Result                                   |
 * |---------------------------------|------------------------------------------|
 * | none                            | no rows                                  |
 * | system                          | no restriction                           |
 * | user (see JurisdictionRules)    | the user's jurisdiction OR active grants |
 *
 * The condition always sits in its own nested where group, so an `orWhere`
 * elsewhere in the query cannot widen it.
 *
 * Never remove this scope outside App\Support\Jurisdiction (enforced by an
 * architecture test). Use JurisdictionContext::runAsSystem() instead.
 */
/**
 * @implements Scope<Model>
 */
final class JurisdictionScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! $model instanceof JurisdictionScoped) {
            throw new LogicException($model::class.' must implement '.JurisdictionScoped::class.' to use JurisdictionScope.');
        }

        $context = app(JurisdictionContext::class);

        if ($context->isSystem()) {
            return;
        }

        $builder->where(function (Builder $query) use ($context, $model): void {
            $user = $context->currentUser();

            if ($user === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $restriction = app(JurisdictionRules::class)->restrictionFor($user, $model->isVisibleToSuperAdmin());

            if ($restriction->type === RestrictionType::Everything) {
                return;
            }

            $this->applyRestriction($query, $model, $restriction);

            if (! $user->isAccessBlocked()) {
                app(AccessGrantResolver::class)->addGrantedRecords($query, $model, $user);
            }
        });
    }

    /**
     * The user's OWN jurisdiction only, without grants or disseminations
     * (Phase 7A): for figures and pickers that must not include records
     * shared with the user. Applied in its own nested group, on top of the
     * global scope.
     *
     * @param  Builder<covariant Model>  $builder
     */
    public function applyOwn(Builder $builder, Model&JurisdictionScoped $model, User $user): void
    {
        $restriction = app(JurisdictionRules::class)->restrictionFor($user, $model->isVisibleToSuperAdmin());

        if ($restriction->type === RestrictionType::Everything) {
            return;
        }

        $builder->where(fn (Builder $query) => $this->applyRestriction($query, $model, $restriction));
    }

    /**
     * @param  Builder<covariant Model>  $query
     */
    private function applyRestriction(Builder $query, Model&JurisdictionScoped $model, JurisdictionRestriction $restriction): void
    {
        $lgaColumn = $model->jurisdictionLgaColumn();

        match ($restriction->type) {
            RestrictionType::States => $query->whereIn($model->qualifyColumn($model->jurisdictionStateColumn()), $restriction->stateIds),
            // An explicit per-model rule for LGA-level users (e.g. offices
            // tied to a senatorial district rather than an LGA).
            RestrictionType::Lga => $model instanceof LgaVisibilityRule && $restriction->lgaId !== null
                ? $query->where(fn (Builder $q) => $model->applyLgaVisibility($q, (int) $restriction->lgaId))
                : ($lgaColumn === null
                ? $query->whereRaw('1 = 0')
                : $query->where($model->qualifyColumn($lgaColumn), $restriction->lgaId)),
            RestrictionType::Nothing => $query->whereRaw('1 = 0'),
            RestrictionType::Everything => null,
        };
    }
}
