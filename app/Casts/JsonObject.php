<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A jsonb object column as a PHP array. Unlike the `array` cast, an empty
 * array is stored as `{}` (not `[]`), so CHECK (jsonb_typeof = 'object')
 * holds.
 *
 * @implements CastsAttributes<array<string, mixed>, array<string, mixed>>
 */
class JsonObject implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return json_encode((object) (is_array($value) ? $value : []), JSON_THROW_ON_ERROR);
    }
}
