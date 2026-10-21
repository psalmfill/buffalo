<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\ConcernLevel;
use App\Enums\FlashpointKind;
use App\Enums\FlashpointStatus;
use App\Enums\IncidentStatus;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\ThreatType;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\DisseminationResolver;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Flashpoints and blackspots: the flashpoint register (Phase 11E.1), with
 * the list page's filters. Linked incidents are counted as the requester
 * may see them (drafts and invalid reports excluded: the statistics rule).
 */
final class FlashpointsExporter extends ListExporter
{
    /** @var array<int, int> flashpoint id => visible linked incidents */
    private array $incidents = [];

    public function __construct(
        private readonly JurisdictionOptions $options,
        private readonly DisseminationResolver $disseminations,
    ) {}

    public function key(): string
    {
        return 'flashpoints';
    }

    public function label(): string
    {
        return 'Flashpoint register';
    }

    public function page(): string
    {
        return 'flashpoints.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', Flashpoint::class);
    }

    public function rules(User $user): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'kind' => ['nullable', Rule::enum(FlashpointKind::class)],
            'concern' => ['nullable', Rule::enum(ConcernLevel::class)],
            'status' => ['nullable', Rule::enum(FlashpointStatus::class)],
            'threat_type' => ['nullable', 'string', 'size:26'],
            'state' => ['nullable', 'string', Rule::in($this->options->states($user)->pluck('ulid'))],
            'zone' => PlaceFilter::zoneRule($user),
            'lga' => ['nullable', 'string', 'size:26'],
            'overdue' => ['nullable', Rule::in(['1'])],
            // Records shared with the user by another jurisdiction (Phase 7A).
            'shared' => ['nullable', Rule::in(['1'])],
        ];
    }

    /**
     * The filtered flashpoints without an order (the page adds its own).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Flashpoint>
     */
    public function filtered(User $user, array $filters): Builder
    {
        $state = isset($filters['state']) ? $this->options->states($user)->firstWhere('ulid', $filters['state']) : null;
        $zone = PlaceFilter::zone($filters['zone'] ?? null);
        $lga = isset($filters['lga']) ? $this->options->lgas($user, $state)->firstWhere('ulid', $filters['lga']) : null;
        $threat = isset($filters['threat_type']) ? ThreatType::query()->where('ulid', $filters['threat_type'])->first() : null;

        return Flashpoint::query()
            ->when($filters['kind'] ?? null, fn (Builder $q, string $v) => $q->where('flashpoints.kind', $v))
            ->when($filters['concern'] ?? null, fn (Builder $q, string $v) => $q->where('flashpoints.concern_level', $v))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v) => $q->where('flashpoints.status', $v))
            ->when($threat, fn (Builder $q) => $q->where('flashpoints.threat_type_id', $threat?->id))
            ->when($state, fn (Builder $q) => $q->where('flashpoints.state_id', $state?->id))
            ->tap(fn (Builder $q) => PlaceFilter::apply($q, 'flashpoints.state_id', $zone))
            ->when($lga, fn (Builder $q) => $q->where('flashpoints.lga_id', $lga?->id))
            ->when(isset($filters['overdue']), fn (Builder $q) => $q
                ->where('flashpoints.status', '!=', FlashpointStatus::Resolved->value)
                ->where('flashpoints.review_due_at', '<', CarbonImmutable::today()->toDateString()))
            ->when($filters['q'] ?? null, fn (Builder $q, string $term) => $q->search($term))
            ->when(isset($filters['shared']), fn (Builder $q) => $this->disseminations->sharedOnly($q, $user));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Flashpoint>
     */
    public function query(User $user, array $filters): Builder
    {
        return $this->filtered($user, $filters)
            ->withCoordinates()
            ->when(! isset($filters['q']), fn (Builder $q) => $q->byConcern());
    }

    public function classificationColumn(): string
    {
        return 'flashpoints.classification';
    }

    public function with(): array
    {
        return ['threatType', 'state', 'lga', 'community'];
    }

    public function prepare(Collection $rows, User $user): void
    {
        $this->incidents = DB::table('flashpoint_incidents')
            ->whereIn('flashpoint_id', $rows->pluck('id')->all())
            ->whereNull('deleted_at')
            ->whereIn('incident_id', Incident::query()->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value])->select('incidents.id'))
            ->groupBy('flashpoint_id')
            ->selectRaw('flashpoint_id, count(*) AS n')
            ->pluck('n', 'flashpoint_id')
            ->map(fn (mixed $n) => (int) $n)
            ->all();
    }

    public function columns(): array
    {
        return [
            ListColumn::make('name', 'Name', fn (Flashpoint $f) => $f->name),
            ListColumn::make('kind', 'Kind', fn (Flashpoint $f) => $f->kind->label()),
            ListColumn::make('threat_type', 'Threat type', fn (Flashpoint $f) => $f->threatType->name),
            ListColumn::make('concern', 'Concern', fn (Flashpoint $f) => $f->concern_level->label()),
            ListColumn::make('status', 'Status', fn (Flashpoint $f) => $f->status->label()),
            ListColumn::make('state', 'State', fn (Flashpoint $f) => $f->state->name),
            ListColumn::make('lga', 'LGA', fn (Flashpoint $f) => $f->lga->name),
            ListColumn::make('community', 'Community', fn (Flashpoint $f) => $f->community?->name),
            ListColumn::make('latitude', 'Latitude', fn (Flashpoint $f) => $f->latitude === null ? null : round((float) $f->latitude, 6)),
            ListColumn::make('longitude', 'Longitude', fn (Flashpoint $f) => $f->longitude === null ? null : round((float) $f->longitude, 6)),
            ListColumn::make('radius', 'Radius (m)', fn (Flashpoint $f) => $f->radius_meters),
            ListColumn::make('last_assessed', 'Last assessed', fn (Flashpoint $f) => self::at($f->last_assessed_at)),
            ListColumn::make('review_due', 'Review due', fn (Flashpoint $f) => self::date($f->review_due_at)),
            ListColumn::make('incidents', 'Linked incidents', fn (Flashpoint $f) => $this->incidents[$f->id] ?? 0),
            ListColumn::make('description', 'Description', fn (Flashpoint $f) => self::text($f, 'description')),
            ListColumn::make('recommended_action', 'Recommended action', fn (Flashpoint $f) => self::text($f, 'recommended_action')),
            ListColumn::make('classification', 'Classification', fn (Flashpoint $f) => $f->classificationLevel()->label()),
        ];
    }

    public function defaultColumns(): array
    {
        return ['name', 'kind', 'threat_type', 'concern', 'status', 'state', 'lga', 'community', 'latitude', 'longitude', 'radius', 'last_assessed', 'review_due', 'incidents', 'classification'];
    }
}
