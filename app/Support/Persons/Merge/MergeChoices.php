<?php

namespace App\Support\Persons\Merge;

/**
 * What the executor chose on the review page (Phase 10A.5).
 *
 * - fields: "survivor" or "source" per conflicting scalar field (missing:
 *   the survivor's value);
 * - place: whose state/LGA the survivor keeps (same state only);
 * - photo: whose current photo becomes the survivor's current photo
 *   (null: the survivor's own);
 * - poiStatus: whose POI status to keep when both are persons of interest;
 * - confirmDob / confirmDeceased: explicit confirmations required when the
 *   dates of birth differ, or only one record is marked deceased.
 */
final readonly class MergeChoices
{
    /**
     * @param  array<string, 'survivor'|'source'>  $fields
     * @param  'survivor'|'source'  $place
     * @param  'survivor'|'source'  $poiStatus
     */
    public function __construct(
        public array $fields = [],
        public string $place = 'survivor',
        public ?string $photo = null,
        public string $poiStatus = 'survivor',
        public bool $confirmDob = false,
        public bool $confirmDeceased = false,
    ) {}

    /** @return 'survivor'|'source' */
    public function field(string $field): string
    {
        return $this->fields[$field] ?? 'survivor';
    }
}
