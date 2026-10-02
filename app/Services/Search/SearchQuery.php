<?php

namespace App\Services\Search;

use App\Support\Persons\SearchTerm;

/**
 * What a global search box entry is (Phase 7B):
 *
 * | Entry                         | Type        | Searched                                   |
 * |-------------------------------|-------------|--------------------------------------------|
 * | `BUF/…` or `THR/…`            | `reference` | exact reference only (incidents, threats)  |
 * | 11 digits                     | `nin`       | persons by NIN (and phone if it starts 0)  |
 * | phone-shaped                  | `phone`     | persons and organization contacts, exact   |
 * | anything else (2+ characters) | `text`      | every provider                             |
 *
 * The plain query is never stored: search_logs keeps only hash().
 */
final readonly class SearchQuery
{
    private function __construct(
        public string $raw,
        public string $type,
        public ?string $reference,
        public ?string $nin,
        public ?string $phone,
    ) {}

    public static function parse(string $input): self
    {
        $raw = trim(preg_replace('/\s+/', ' ', $input) ?? '');

        if (preg_match('#^(BUF|THR)/\S+$#i', $raw) === 1) {
            return new self($raw, 'reference', mb_strtoupper($raw), null, null);
        }

        $term = SearchTerm::parse($raw);

        return match (true) {
            $term->nin !== null => new self($raw, 'nin', null, $term->nin, $term->phone),
            $term->phone !== null => new self($raw, 'phone', null, null, $term->phone),
            default => new self($raw, 'text', null, null, null),
        };
    }

    /** One character or less shows nothing. */
    public function isSearchable(): bool
    {
        return mb_strlen($this->raw) >= (int) config('buffalo.search.min_length');
    }

    public function isText(): bool
    {
        return $this->type === 'text';
    }

    public function isNumber(): bool
    {
        return $this->nin !== null || $this->phone !== null;
    }

    public function referencePrefix(): ?string
    {
        if ($this->reference === null) {
            return null;
        }

        return explode('/', $this->reference, 2)[0];
    }

    /** The persons search term (names, NIN, phone) for PersonSearch. */
    public function personTerm(): SearchTerm
    {
        return SearchTerm::parse($this->raw);
    }

    /** SHA-256 of the normalised query: what search_logs stores instead of the query. */
    public function hash(): string
    {
        return hash('sha256', mb_strtolower($this->raw));
    }
}
