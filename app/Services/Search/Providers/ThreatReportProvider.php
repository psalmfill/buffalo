<?php

namespace App\Services\Search\Providers;

use App\Enums\Permission;
use App\Models\ThreatReport;
use App\Models\User;
use App\Services\Search\SearchHit;
use App\Services\Search\SearchQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * Threat reports: an exact `THR/…` reference (unique index), or the
 * generated `search_vector` (title, description; GIN). Disseminated reports
 * come through the scopes like any other visible report.
 */
final class ThreatReportProvider extends BaseProvider
{
    public function key(): string
    {
        return 'threats';
    }

    public function label(): string
    {
        return 'Threat reports';
    }

    protected function permission(): string
    {
        return Permission::ThreatsView->value;
    }

    public function supports(SearchQuery $query): bool
    {
        return $query->isText() || $query->referencePrefix() === 'THR';
    }

    public function hits(SearchQuery $query, User $user, int $limit, int $offset = 0): array
    {
        return array_values($this->query($query)
            ->with(['threatType', 'lga'])
            ->orderByDesc('search_score')
            ->orderByDesc('threat_reports.created_at')
            ->offset($offset)->limit($limit)->get()
            ->map(fn (ThreatReport $t) => new SearchHit(
                'threats',
                $t->ulid,
                $t->title,
                self::subtitle($t->reference_no, $t->threatType->name, $t->urgency->label(), $t->lga->name.' LGA', $t->status->label()),
                '/threats/'.$t->ulid,
                $t->classificationLevel(),
                self::score($t->getAttribute('search_score')),
                $query->reference !== null,
                $t->created_at,
            ))->all());
    }

    public function count(SearchQuery $query, User $user): int
    {
        return $this->query($query, scored: false)->count();
    }

    /**
     * @return Builder<ThreatReport>
     */
    private function query(SearchQuery $query, bool $scored = true): Builder
    {
        $base = ThreatReport::query();

        if ($query->reference !== null) {
            return $base->where('threat_reports.reference_no', $query->reference)
                ->when($scored, fn (Builder $q) => $q->select('threat_reports.*')->selectRaw('1.0 AS search_score'));
        }

        return $base->whereRaw("threat_reports.search_vector @@ websearch_to_tsquery('english', ?)", [$query->raw])
            ->when($scored, fn (Builder $q) => $q->select('threat_reports.*')
                ->selectRaw("ts_rank_cd(threat_reports.search_vector, websearch_to_tsquery('english', ?), 32) AS search_score", [$query->raw]));
    }
}
