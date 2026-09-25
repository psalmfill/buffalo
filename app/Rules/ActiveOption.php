<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;

/**
 * The value is the ULID of an ACTIVE record of $model. Deactivated values
 * cannot be chosen for new selections; $keep (the record's current value)
 * stays valid so existing records remain editable.
 */
final class ActiveOption implements ValidationRule
{
    /**
     * @param  class-string<Model>  $model  a model with `ulid` and `is_active`
     */
    public function __construct(
        private readonly string $model,
        private readonly ?string $keep = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The selected :attribute is invalid.');

            return;
        }

        $record = $this->model::query()->where('ulid', $value)->first(['id', 'is_active']);

        if ($record === null) {
            $fail('The selected :attribute is invalid.');
        } elseif (! $record->getAttribute('is_active') && $value !== $this->keep) {
            $fail('The selected :attribute is no longer available.');
        }
    }
}
