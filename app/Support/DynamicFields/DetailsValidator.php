<?php

namespace App\Support\DynamicFields;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Validates a record's `details` payload against its type's schema and
 * returns the cleaned, correctly typed values.
 *
 * - Unknown keys are rejected, never silently dropped.
 * - Empty optional values are left out of the result.
 * - Deprecated fields and options cannot be chosen for new values; a value
 *   the record already had ($existing) is kept when it is resubmitted
 *   unchanged, so old records stay editable.
 */
final class DetailsValidator
{
    /**
     * @param  list<FieldDefinition>  $schema
     * @param  array<string, mixed>  $existing  the record's saved details (empty for a new record)
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(array $schema, mixed $details, array $existing = [], string $attribute = 'details'): array
    {
        $details ??= [];

        if (! is_array($details) || (array_is_list($details) && $details !== [])) {
            throw ValidationException::withMessages([$attribute => 'The details must be an object of field values.']);
        }

        $fields = [];
        foreach ($schema as $field) {
            $fields[$field->key] = $field;
        }

        $errors = [];
        foreach (array_keys($details) as $key) {
            if (! isset($fields[$key])) {
                $errors["{$attribute}.{$key}"] = ['This field is not part of the incident type.'];
            }
        }

        $rules = [];
        $messages = [];
        $names = [];
        foreach ($fields as $key => $field) {
            $rules[$key] = $this->rules($field, $existing[$key] ?? null);

            if ($field->type === FieldType::Multiselect && ! $field->deprecated) {
                $rules["{$key}.*"] = ['string', 'distinct', Rule::in($this->allowedOptions($field, $existing[$key] ?? null))];
            }
            $names[$key] = $field->label;
            $messages["{$key}.in"] = 'The selected :attribute is not available.';
            $messages["{$key}.*.in"] = 'A selected option for :attribute is not available.';
            $messages["{$key}.prohibited"] = 'The :attribute field is no longer used.';
        }

        $validator = Validator::make($details, $rules, $messages, $names);

        foreach ($validator->errors()->toArray() as $key => $keyErrors) {
            $errors["{$attribute}.{$key}"] = $keyErrors;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $clean = [];
        foreach ($fields as $key => $field) {
            $value = $this->cast($field, $details[$key] ?? null);

            if ($value !== null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * @return list<mixed>
     */
    private function rules(FieldDefinition $field, mixed $existing): array
    {
        if ($field->deprecated) {
            // Hidden from new entry: only the value the record already has.
            return $existing === null
                ? ['prohibited']
                : ['nullable', fn (string $attribute, mixed $value, \Closure $fail) => $value === null || $value === $existing
                    ? null
                    : $fail('The :attribute field is no longer used; its saved value cannot be changed.')];
        }

        $rules = [$field->required ? 'required' : 'nullable'];

        match ($field->type) {
            FieldType::Text, FieldType::Textarea => array_push($rules, 'string', 'max:'.$field->effectiveMaxLength()),
            FieldType::Integer => array_push($rules, 'integer', ...$this->bounds($field)),
            FieldType::Number => array_push($rules, 'numeric', ...$this->bounds($field)),
            FieldType::Boolean => array_push($rules, 'boolean'),
            FieldType::Date => array_push($rules, 'date_format:Y-m-d'),
            FieldType::Datetime => array_push($rules, 'date_format:Y-m-d\TH:i,Y-m-d\TH:i:s,Y-m-d H:i,Y-m-d H:i:s'),
            FieldType::Select => array_push($rules, 'string', Rule::in($this->allowedOptions($field, $existing))),
            FieldType::Multiselect => array_push($rules, 'array', 'list'),
        };

        return $rules;
    }

    /**
     * @return list<string>
     */
    private function bounds(FieldDefinition $field): array
    {
        return array_values(array_filter([
            $field->min !== null ? 'min:'.$field->min : null,
            $field->max !== null ? 'max:'.$field->max : null,
        ]));
    }

    /**
     * Available options, plus deprecated ones the record already has.
     *
     * @return list<string>
     */
    private function allowedOptions(FieldDefinition $field, mixed $existing): array
    {
        $kept = is_array($existing) ? $existing : [$existing];

        $allowed = [];
        foreach ($field->options as $option) {
            if (! $option->deprecated || in_array($option->value, $kept, true)) {
                $allowed[] = $option->value;
            }
        }

        return $allowed;
    }

    private function cast(FieldDefinition $field, mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === []) {
            // An unset boolean stays unset (unknown), not false.
            return null;
        }

        return match ($field->type) {
            FieldType::Text, FieldType::Textarea, FieldType::Select => (string) $value,
            FieldType::Integer => (int) $value,
            FieldType::Number => $this->number($value),
            FieldType::Boolean => filter_var($value, FILTER_VALIDATE_BOOL),
            FieldType::Date => (string) $value,
            FieldType::Datetime => CarbonImmutable::parse((string) $value)->format('Y-m-d\TH:i'),
            FieldType::Multiselect => $this->orderedOptions($field, (array) $value),
        };
    }

    private function number(mixed $value): int|float
    {
        $number = (float) $value;

        return floor($number) === $number && abs($number) < PHP_INT_MAX ? (int) $number : $number;
    }

    /**
     * Multiselect values in the schema's option order.
     *
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function orderedOptions(FieldDefinition $field, array $values): array
    {
        $chosen = array_map('strval', $values);

        return array_values(array_filter(
            array_map(fn (FieldOption $option): string => $option->value, $field->options),
            fn (string $value): bool => in_array($value, $chosen, true),
        ));
    }
}
