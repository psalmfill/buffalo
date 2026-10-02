<?php

namespace App\Services\Search;

use App\Enums\Classification;
use Carbon\CarbonInterface;

/**
 * One global search result (Phase 7B). `score` is normalised to 0–1;
 * `exact` marks a reference, NIN or phone match (always ranked first).
 * Listing a hit is not viewing the record: nothing is access-logged here.
 */
final readonly class SearchHit
{
    public function __construct(
        public string $type,
        public string $ulid,
        public string $title,
        public string $subtitle,
        public string $href,
        public ?Classification $classification,
        public float $score,
        public bool $exact = false,
        public ?CarbonInterface $at = null,
        public ?string $badge = null,
    ) {}

    /**
     * @return array{type: string, ulid: string, title: string, subtitle: string, href: string, classification: array{value: string, label: string}|null, score: float, exact: bool, badge: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'ulid' => $this->ulid,
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'href' => $this->href,
            'classification' => $this->classification === null ? null : ['value' => $this->classification->value, 'label' => $this->classification->label()],
            'score' => round(max(0.0, min(1.0, $this->score)), 3),
            'exact' => $this->exact,
            'badge' => $this->badge,
        ];
    }

    /**
     * Exact matches first, then by score, then the most recent.
     *
     * @param  list<self>  $hits
     * @return list<self>
     */
    public static function rank(array $hits): array
    {
        usort($hits, fn (self $a, self $b) => [$b->exact, round($b->score, 3), $b->at?->getTimestamp() ?? 0]
            <=> [$a->exact, round($a->score, 3), $a->at?->getTimestamp() ?? 0]);

        return $hits;
    }
}
