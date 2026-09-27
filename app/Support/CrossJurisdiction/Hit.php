<?php

namespace App\Support\CrossJurisdiction;

use App\Enums\MatchType;

/**
 * A record in another jurisdiction that matched (Phase 3C). Reveals ONLY
 * the owning state's name, the category and the match strength; the token
 * (encrypted, bound to the user, short-lived) is the only way to request
 * access. Never a name, alias, photo, LGA, offence or identifier.
 */
final readonly class Hit
{
    public const CATEGORY_POI = 'Person of interest';

    public const CATEGORY_PERSON = 'Person record';

    public function __construct(
        public string $token,
        public string $state,
        public string $category,
        public MatchType $strength,
    ) {}

    /**
     * @return array{token: string, state: string, category: string, strength: string}
     */
    public function toArray(): array
    {
        return [
            'token' => $this->token,
            'state' => $this->state,
            'category' => $this->category,
            'strength' => $this->strength->label(),
        ];
    }
}
