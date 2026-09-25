<?php

namespace App\Support\DynamicFields;

use Illuminate\Validation\ValidationException;

/**
 * Validates a details_schema itself (when an administrator edits an
 * incident type's fields) and, for a type already in use, that the change
 * is compatible with the records that use it.
 *
 * Errors are keyed "{attribute}.{index}.{property}" so the field builder can
 * show them next to the field.
 */
final class SchemaValidator
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*$/';

    private const FIELD_PROPERTIES = ['key', 'label', 'type', 'required', 'options', 'min', 'max', 'max_length', 'help', 'deprecated'];

    private const OPTION_PROPERTIES = ['value', 'label', 'deprecated'];

    /** @var array<string, list<string>> */
    private array $errors = [];

    /**
     * Checks the format and returns the schema as definitions.
     *
     * @return list<FieldDefinition>
     *
     * @throws ValidationException
     */
    public function validate(mixed $schema, string $attribute = 'schema'): array
    {
        $this->errors = [];

        if (! is_array($schema) || ! array_is_list($schema)) {
            throw ValidationException::withMessages([$attribute => 'The detail fields must be a list.']);
        }

        $keys = [];

        foreach ($schema as $index => $field) {
            $this->validateField($field, "{$attribute}.{$index}", $keys);
        }

        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }

        /** @var list<array<string, mixed>> $schema */
        return FieldDefinition::listFromSchema($schema);
    }

    /**
     * Checks that $proposed can replace $current for a type that records
     * already use: fields may be added, relabelled, reordered, deprecated or
     * given new options; keys cannot be renamed or removed, types cannot
     * change, and options cannot be removed (only deprecated).
     *
     * @param  list<FieldDefinition>  $current
     * @param  list<FieldDefinition>  $proposed
     *
     * @throws ValidationException
     */
    public function assertCompatible(array $current, array $proposed, string $attribute = 'schema'): void
    {
        $this->errors = [];

        $byKey = [];
        foreach ($proposed as $index => $field) {
            $byKey[$field->key] = [$index, $field];
        }

        foreach ($current as $old) {
            if (! isset($byKey[$old->key])) {
                $this->fail($attribute, "The field \"{$old->key}\" cannot be removed or renamed because this incident type is in use. Deprecate it instead.");

                continue;
            }

            [$index, $new] = $byKey[$old->key];

            if ($new->type !== $old->type) {
                $this->fail("{$attribute}.{$index}.type", "The type of \"{$old->key}\" cannot change from {$old->type->value} to {$new->type->value} because this incident type is in use.");
            }

            foreach ($old->options as $option) {
                if ($new->option($option->value) === null) {
                    $this->fail("{$attribute}.{$index}.options", "The option \"{$option->value}\" of \"{$old->key}\" cannot be removed or renamed because this incident type is in use. Deprecate it instead.");
                }
            }
        }

        if ($this->errors !== []) {
            throw ValidationException::withMessages($this->errors);
        }
    }

    /**
     * @param  array<string, true>  $keys  keys seen so far
     */
    private function validateField(mixed $field, string $path, array &$keys): void
    {
        if (! is_array($field) || array_is_list($field) && $field !== []) {
            $this->fail($path, 'Each field must be an object.');

            return;
        }

        foreach (array_diff(array_keys($field), self::FIELD_PROPERTIES) as $unknown) {
            $this->fail($path, "Unknown property \"{$unknown}\".");
        }

        $key = $field['key'] ?? null;
        if (! is_string($key) || $key === '') {
            $this->fail("{$path}.key", 'The key is required.');
        } elseif (strlen($key) > 64 || ! preg_match(self::KEY_PATTERN, $key)) {
            $this->fail("{$path}.key", 'The key must be lowercase snake_case: letters, digits and underscores, starting with a letter (at most 64 characters).');
        } elseif (isset($keys[$key])) {
            $this->fail("{$path}.key", "The key \"{$key}\" is used more than once.");
        } else {
            $keys[$key] = true;
        }

        $label = $field['label'] ?? null;
        if (! is_string($label) || trim($label) === '') {
            $this->fail("{$path}.label", 'The label is required.');
        } elseif (mb_strlen($label) > 255) {
            $this->fail("{$path}.label", 'The label may not be longer than 255 characters.');
        }

        $type = is_string($field['type'] ?? null) ? FieldType::tryFrom($field['type']) : null;
        if ($type === null) {
            $this->fail("{$path}.type", 'The type must be one of: '.implode(', ', array_column(FieldType::cases(), 'value')).'.');
        }

        foreach (['required', 'deprecated'] as $flag) {
            if (array_key_exists($flag, $field) && ! is_bool($field[$flag])) {
                $this->fail("{$path}.{$flag}", "\"{$flag}\" must be true or false.");
            }
        }

        if (($field['required'] ?? false) === true && ($field['deprecated'] ?? false) === true) {
            $this->fail("{$path}.required", 'A deprecated field cannot be required.');
        }

        if (array_key_exists('help', $field) && $field['help'] !== null && (! is_string($field['help']) || mb_strlen($field['help']) > 500)) {
            $this->fail("{$path}.help", 'The help text must be text of at most 500 characters.');
        }

        if ($type !== null) {
            $this->validateOptions($field, $type, $path);
            $this->validateBounds($field, $type, $path);
            $this->validateMaxLength($field, $type, $path);
        }
    }

    /**
     * @param  array<array-key, mixed>  $field
     */
    private function validateOptions(array $field, FieldType $type, string $path): void
    {
        $options = $field['options'] ?? null;

        if (! $type->hasOptions()) {
            if ($options !== null) {
                $this->fail("{$path}.options", 'Only select and multiselect fields have options.');
            }

            return;
        }

        if (! is_array($options) || ! array_is_list($options) || $options === []) {
            $this->fail("{$path}.options", 'Select and multiselect fields need at least one option.');

            return;
        }

        $values = [];
        $active = 0;

        foreach ($options as $i => $option) {
            $optionPath = "{$path}.options.{$i}";

            if (! is_array($option) || array_is_list($option)) {
                $this->fail($optionPath, 'Each option must have a value and a label.');

                continue;
            }

            foreach (array_diff(array_keys($option), self::OPTION_PROPERTIES) as $unknown) {
                $this->fail($optionPath, "Unknown property \"{$unknown}\".");
            }

            $value = $option['value'] ?? null;
            if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 100) {
                $this->fail("{$optionPath}.value", 'Each option needs a value (at most 100 characters).');
            } elseif (isset($values[$value])) {
                $this->fail("{$optionPath}.value", "The option value \"{$value}\" is used more than once.");
            } else {
                $values[$value] = true;
            }

            $label = $option['label'] ?? null;
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 255) {
                $this->fail("{$optionPath}.label", 'Each option needs a label (at most 255 characters).');
            }

            if (array_key_exists('deprecated', $option) && ! is_bool($option['deprecated'])) {
                $this->fail("{$optionPath}.deprecated", '"deprecated" must be true or false.');
            }

            if (($option['deprecated'] ?? false) !== true) {
                $active++;
            }
        }

        if ($active === 0 && ($field['deprecated'] ?? false) !== true) {
            $this->fail("{$path}.options", 'At least one option must remain available.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $field
     */
    private function validateBounds(array $field, FieldType $type, string $path): void
    {
        $bounds = [];

        foreach (['min', 'max'] as $bound) {
            if (! array_key_exists($bound, $field) || $field[$bound] === null) {
                continue;
            }

            if (! $type->isNumeric()) {
                $this->fail("{$path}.{$bound}", 'Minimum and maximum apply only to integer and number fields.');

                continue;
            }

            $value = $field[$bound];

            if (! is_int($value) && ! is_float($value)) {
                $this->fail("{$path}.{$bound}", "\"{$bound}\" must be a number.");
            } elseif ($type === FieldType::Integer && floor($value) !== (float) $value) {
                $this->fail("{$path}.{$bound}", "\"{$bound}\" must be a whole number for an integer field.");
            } else {
                $bounds[$bound] = $value;
            }
        }

        if (isset($bounds['min'], $bounds['max']) && $bounds['min'] > $bounds['max']) {
            $this->fail("{$path}.max", 'The maximum must not be less than the minimum.');
        }
    }

    /**
     * @param  array<array-key, mixed>  $field
     */
    private function validateMaxLength(array $field, FieldType $type, string $path): void
    {
        if (! array_key_exists('max_length', $field) || $field['max_length'] === null) {
            return;
        }

        $limit = $type->maxLengthLimit();

        if ($limit === null) {
            $this->fail("{$path}.max_length", 'A maximum length applies only to text and textarea fields.');
        } elseif (! is_int($field['max_length']) || $field['max_length'] < 1 || $field['max_length'] > $limit) {
            $this->fail("{$path}.max_length", "The maximum length must be a whole number from 1 to {$limit}.");
        }
    }

    private function fail(string $key, string $message): void
    {
        $this->errors[$key][] = $message;
    }
}
