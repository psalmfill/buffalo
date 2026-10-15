<?php

namespace App\Rules;

use App\Support\RichText\RichText;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A rich text field (Phase 11B), checked on its PLAIN TEXT: required means
 * some text (formatting alone is empty), and min and max count plain text
 * characters. The HTML itself may be at most html_factor times the limit.
 * Use with 'nullable' or 'required' as before:
 *
 *     'description' => ['required', new RichTextRule(20000, required: true)]
 */
final class RichTextRule implements ValidationRule
{
    public function __construct(
        private readonly int $max,
        private readonly bool $required = false,
        private readonly int $min = 0,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be text.');

            return;
        }

        if (strlen($value) > RichText::htmlLimit($this->max)) {
            $fail('The :attribute is too long.');

            return;
        }

        $length = RichText::sanitize($value)?->length() ?? 0;

        if ($length === 0) {
            if ($this->required) {
                $fail('The :attribute field is required.');
            }

            return;
        }

        if ($length > $this->max) {
            $fail("The :attribute may not be greater than {$this->max} characters (it has {$length}).");
        } elseif ($length < $this->min) {
            $fail("The :attribute must be at least {$this->min} characters.");
        }
    }
}
