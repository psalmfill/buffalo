<?php

namespace App\Actions\ReferenceData;

use App\Models\ReferenceModel;
use App\Models\User;
use App\Support\ReferenceData\ReferenceTable;

/**
 * Creates a reference value, or updates one. $attributes are validated
 * column values (ReferenceValueRequest::attributes()); `code` is only
 * written on create.
 */
final class SaveReferenceValue
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(ReferenceTable $table, array $attributes, User $actor): ReferenceModel
    {
        /** @var ReferenceModel $value */
        $value = $table->newModel();

        $value->forceFill([
            ...$attributes,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ])->save();

        return $value;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(ReferenceModel $value, array $attributes, User $actor): void
    {
        unset($attributes['code']);

        $value->forceFill([...$attributes, 'updated_by' => $actor->id]);

        if ($value->isDirty()) {
            $value->save();
        }
    }

    public function setActive(ReferenceModel $value, bool $active, User $actor): void
    {
        if ($value->getAttribute('is_active') === $active) {
            return;
        }

        $value->forceFill(['is_active' => $active, 'updated_by' => $actor->id])->save();
    }
}
