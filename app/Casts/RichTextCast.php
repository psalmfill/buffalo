<?php

namespace App\Casts;

use App\Support\RichText\RichText;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A rich text column (Phase 11B). Setting it always runs the sanitiser
 * and fills the plain text copy in `<column>_text`; text without any
 * content is stored as null in both. Reading returns the stored
 * (sanitised) HTML.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
class RichTextCast implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, string|null>
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        $rich = RichText::sanitize(is_string($value) ? $value : null);

        return [$key => $rich?->html, "{$key}_text" => $rich?->text];
    }
}
