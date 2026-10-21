<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Http\Presenters\BlackspotSuggestionRows;
use App\Models\BlackspotSuggestion;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Open blackspot suggestions (Phase 6A list, 11E export): counts recomputed
 * through the requester's scopes, hidden below min_points visible
 * incidents, busiest first, exactly as on the page.
 */
final class BlackspotSuggestionsExporter extends ListExporter
{
    public function key(): string
    {
        return 'blackspot_suggestions';
    }

    public function label(): string
    {
        return 'Blackspot suggestions';
    }

    public function page(): string
    {
        return 'blackspot-suggestions.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', BlackspotSuggestion::class);
    }

    public function rules(User $user): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<BlackspotSuggestion>
     */
    public function query(User $user, array $filters): Builder
    {
        return BlackspotSuggestionRows::visible(BlackspotSuggestion::query()->where('blackspot_suggestions.status', 'open'));
    }

    public function with(): array
    {
        return ['state', 'lga', 'dominantType'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('lga', 'LGA', fn (BlackspotSuggestion $s) => $s->lga->name),
            ListColumn::make('state', 'State', fn (BlackspotSuggestion $s) => $s->state->name),
            ListColumn::make('incidents', 'Incidents (visible to you)', fn (BlackspotSuggestion $s) => (int) $s->getAttribute('visible_incidents')),
            ListColumn::make('dominant_type', 'Dominant type', fn (BlackspotSuggestion $s) => $s->dominantType?->name),
            ListColumn::make('category', 'Category', fn (BlackspotSuggestion $s) => $s->category),
            ListColumn::make('latitude', 'Latitude', fn (BlackspotSuggestion $s) => $s->latitude === null ? null : round((float) $s->latitude, 6)),
            ListColumn::make('longitude', 'Longitude', fn (BlackspotSuggestion $s) => $s->longitude === null ? null : round((float) $s->longitude, 6)),
            ListColumn::make('radius', 'Radius (m)', fn (BlackspotSuggestion $s) => $s->radius_meters),
            ListColumn::make('first', 'First incident', fn (BlackspotSuggestion $s) => self::at($s->first_occurred_at)),
            ListColumn::make('last', 'Latest incident', fn (BlackspotSuggestion $s) => self::at($s->last_occurred_at)),
            ListColumn::make('generated', 'Suggested', fn (BlackspotSuggestion $s) => self::at($s->generated_at)),
        ];
    }

    public function defaultColumns(): array
    {
        return array_map(fn (ListColumn $c) => $c->key, $this->columns());
    }
}
