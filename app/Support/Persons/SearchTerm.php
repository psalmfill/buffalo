<?php

namespace App\Support\Persons;

/**
 * What a persons search box entry is: a NIN (11 digits), a phone number,
 * or a name. An 11-digit number starting with 0 is also a valid local phone
 * number (0803…), so it is searched as both.
 */
final readonly class SearchTerm
{
    private function __construct(
        public string $raw,
        public ?string $nin,
        public ?string $phone,
    ) {}

    public static function parse(string $input): self
    {
        $raw = trim($input);
        $digits = preg_replace('/\s+/', '', $raw) ?? '';

        $nin = preg_match('/^\d{11}$/', $digits) === 1 ? $digits : null;
        // Only digit-like entries can be phone numbers ("+234 803-123-4567").
        $phone = preg_match('/^[\d\s().+\-\/]+$/', $raw) === 1 ? PhoneNumber::normalise($raw) : null;

        return new self($raw, $nin, $phone);
    }

    public function isEmpty(): bool
    {
        return $this->raw === '';
    }

    /** A NIN and/or phone search (blind indexes, exact match). */
    public function isNumber(): bool
    {
        return $this->nin !== null || $this->phone !== null;
    }

    /** "nin", "phone", "nin_or_phone", "name" or "none". */
    public function kind(): string
    {
        return match (true) {
            $this->isEmpty() => 'none',
            $this->nin !== null && $this->phone !== null => 'nin_or_phone',
            $this->nin !== null => 'nin',
            $this->phone !== null => 'phone',
            default => 'name',
        };
    }
}
