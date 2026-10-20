<?php

namespace App\Rules;

use App\Support\Persons\PhotoSanitiser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * An image that PhotoSanitiser can re-encode: JPEG, PNG or WebP by content
 * (never by name; SVG is never accepted), of sane dimensions. Used with
 * `file`, `max` and `mimetypes` (Phase 11D: logos and site photos).
 */
final class ImageFile implements ValidationRule
{
    public function __construct(private readonly string $noun = 'image') {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail("The {$this->noun} could not be uploaded.");

            return;
        }

        $problem = PhotoSanitiser::problem((string) $value->getRealPath(), $this->noun);

        if ($problem !== null) {
            $fail($problem);
        }
    }
}
