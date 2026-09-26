<?php

namespace App\Support\Classification;

use App\Enums\Classification;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope applied by App\Models\Concerns\HasClassification. It is
 * added alongside JurisdictionScope, so a record must pass both.
 *
 * | Context | Result                                                   |
 * |---------|----------------------------------------------------------|
 * | none    | no rows                                                  |
 * | system  | no restriction                                           |
 * | user    | levels up to the user's maximum, or records they created |
 *
 * The condition sits in its own nested where group.
 *
 * @implements Scope<Model>
 */
final class ClassificationScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(JurisdictionContext::class);

        if ($context->isSystem()) {
            return;
        }

        $builder->where(function (Builder $query) use ($context, $model): void {
            $user = $context->currentUser();
            $max = $user === null ? null : app(ClassificationGate::class)->maxLevelFor($user);

            if ($user === null || $max === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->whereIn($model->qualifyColumn('classification'), array_map(
                fn (Classification $level): string => $level->value,
                Classification::upTo($max),
            ))->orWhere($model->qualifyColumn('created_by'), $user->id);
        });
    }
}
