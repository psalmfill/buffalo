<?php

namespace App\Support\DynamicFields;

/**
 * One choice of a select or multiselect field. Options are never removed
 * once a type is in use, only deprecated (hidden from new entries).
 */
final readonly class FieldOption
{
    public function __construct(
        public string $value,
        public string $label,
        public bool $deprecated = false,
    ) {}

    /**
     * @return array{value: string, label: string, deprecated?: true}
     */
    public function toArray(): array
    {
        return [
            'value' => $this->value,
            'label' => $this->label,
            ...($this->deprecated ? ['deprecated' => true] : []),
        ];
    }
}
