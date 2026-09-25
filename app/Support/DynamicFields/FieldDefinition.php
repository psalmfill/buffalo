<?php

namespace App\Support\DynamicFields;

/**
 * One field of an incident type's details_schema (BUFFALO's own format, not
 * JSON Schema). Build instances only from a schema that SchemaValidator has
 * accepted.
 */
final readonly class FieldDefinition
{
    /**
     * @param  list<FieldOption>  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public FieldType $type,
        public bool $required = false,
        public array $options = [],
        public int|float|null $min = null,
        public int|float|null $max = null,
        public ?int $maxLength = null,
        public ?string $help = null,
        public bool $deprecated = false,
    ) {}

    /**
     * @param  array<string, mixed>  $field  a validated field definition
     */
    public static function fromArray(array $field): self
    {
        /** @var list<array{value: string, label: string, deprecated?: bool}> $options */
        $options = $field['options'] ?? [];

        return new self(
            key: (string) $field['key'],
            label: (string) $field['label'],
            type: FieldType::from((string) $field['type']),
            required: (bool) ($field['required'] ?? false),
            options: array_map(
                fn (array $option): FieldOption => new FieldOption($option['value'], $option['label'], (bool) ($option['deprecated'] ?? false)),
                $options,
            ),
            min: self::number($field['min'] ?? null),
            max: self::number($field['max'] ?? null),
            maxLength: isset($field['max_length']) ? (int) $field['max_length'] : null,
            help: isset($field['help']) && $field['help'] !== '' ? (string) $field['help'] : null,
            deprecated: (bool) ($field['deprecated'] ?? false),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $schema  a validated schema
     * @return list<self>
     */
    public static function listFromSchema(array $schema): array
    {
        return array_map(fn (array $field): self => self::fromArray($field), $schema);
    }

    public function effectiveMaxLength(): ?int
    {
        return $this->maxLength ?? $this->type->maxLengthLimit();
    }

    public function option(string $value): ?FieldOption
    {
        foreach ($this->options as $option) {
            if ($option->value === $value) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Canonical stored form: only the properties that apply, defaults left out.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'required' => $this->required,
            'options' => $this->type->hasOptions() ? array_map(fn (FieldOption $option): array => $option->toArray(), $this->options) : null,
            'min' => $this->type->isNumeric() ? $this->min : null,
            'max' => $this->type->isNumeric() ? $this->max : null,
            'max_length' => $this->type->isText() ? $this->maxLength : null,
            'help' => $this->help,
            'deprecated' => $this->deprecated ?: null,
        ], fn (mixed $value): bool => $value !== null);
    }

    private static function number(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        $number = (float) $value;

        return floor($number) === $number && abs($number) < PHP_INT_MAX ? (int) $number : $number;
    }
}
