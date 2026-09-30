<?php

namespace App\Support\CrossJurisdiction;

use App\Support\Persons\PhoneNumber;
use App\Support\Persons\SearchTerm;

/**
 * What to match persons against (Phase 3C): a name and aliases, and the
 * identifiers compared by blind index (NIN, phone, date of birth). Built
 * from a search box entry or from an unsaved person form.
 */
final readonly class MatchCriteria
{
    /**
     * @param  list<string>  $aliases
     */
    public function __construct(
        public ?string $name = null,
        public array $aliases = [],
        public ?string $dateOfBirth = null,
        public ?string $nin = null,
        public ?string $phone = null,
        public ?string $gender = null,
    ) {}

    /**
     * A search box entry. The optional date of birth (Phase 4A.0) lets a
     * name produce `strong` matches; a name alone never produces a hit.
     */
    public static function fromSearchTerm(SearchTerm $term, ?string $dateOfBirth = null): self
    {
        if ($term->isNumber()) {
            return new self(nin: $term->nin, phone: $term->phone);
        }

        return new self(name: $term->isEmpty() ? null : $term->raw, dateOfBirth: $term->isEmpty() ? null : $dateOfBirth);
    }

    /**
     * @param  array<string, mixed>  $input  person form values
     */
    public static function fromPersonInput(array $input): self
    {
        $name = trim(implode(' ', array_filter([
            self::string($input['first_name'] ?? null),
            self::string($input['other_names'] ?? null),
            self::string($input['last_name'] ?? null),
        ])));
        $nin = self::string($input['nin'] ?? null);
        $phone = self::string($input['phone'] ?? null);

        return new self(
            name: $name === '' ? null : $name,
            aliases: array_values(array_filter(array_map(self::string(...), (array) ($input['aliases'] ?? [])))),
            dateOfBirth: self::string($input['date_of_birth'] ?? null),
            nin: $nin === null ? null : (preg_replace('/\s+/', '', $nin) ?: null),
            phone: $phone === null ? null : PhoneNumber::normalise($phone),
            gender: self::string($input['gender'] ?? null),
        );
    }

    public function isEmpty(): bool
    {
        return $this->name === null && $this->aliases === [] && $this->nin === null && $this->phone === null;
    }

    /**
     * Names to compare: the name and every alias.
     *
     * @return list<string>
     */
    public function names(): array
    {
        return array_values(array_unique(array_filter([$this->name, ...$this->aliases])));
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
