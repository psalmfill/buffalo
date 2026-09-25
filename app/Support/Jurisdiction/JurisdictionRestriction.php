<?php

namespace App\Support\Jurisdiction;

/**
 * What a user may see, independent of how it is applied: JurisdictionScope
 * turns it into SQL, JurisdictionGate evaluates it against one record. Both
 * use the same restriction, so they cannot disagree.
 */
final readonly class JurisdictionRestriction
{
    /**
     * @param  list<int>  $stateIds
     */
    private function __construct(
        public RestrictionType $type,
        public array $stateIds = [],
        public ?int $lgaId = null,
    ) {}

    public static function nothing(): self
    {
        return new self(RestrictionType::Nothing);
    }

    public static function everything(): self
    {
        return new self(RestrictionType::Everything);
    }

    /**
     * @param  list<int>  $stateIds
     */
    public static function states(array $stateIds): self
    {
        return $stateIds === [] ? self::nothing() : new self(RestrictionType::States, array_values(array_unique($stateIds)));
    }

    public static function lga(int $lgaId): self
    {
        return new self(RestrictionType::Lga, lgaId: $lgaId);
    }
}
