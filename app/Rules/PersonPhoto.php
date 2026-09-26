<?php

namespace App\Rules;

use App\Support\Persons\PhotoSanitiser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * A person photo: JPEG, PNG or WebP by content (not by name), of sane
 * dimensions. Used with `file`, `max` and `mimetypes`.
 */
final class PersonPhoto implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('The photo could not be uploaded.');

            return;
        }

        $problem = PhotoSanitiser::problem((string) $value->getRealPath());

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
