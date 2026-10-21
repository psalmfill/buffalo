<?php

namespace App\Support\Exports\Lists\Exporters;

use App\Enums\ThreatCredibility;
use App\Enums\ThreatStatus;
use App\Enums\ThreatUrgency;
use App\Models\ThreatReport;
use App\Models\ThreatType;
use App\Models\User;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Jurisdiction\DisseminationResolver;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;

/**
 * Threat reports (Phase 6C list, 11E export). Like the page, the default
 * is active threats only; "any" covers every status. Source notes are
 * never exported.
 */
final class ThreatReportsExporter extends ListExporter
{
    public function __construct(
        private readonly JurisdictionOptions $options,
        private readonly DisseminationResolver $disseminations,
    ) {}

    public function key(): string
    {
        return 'threats';
    }

    public function label(): string
    {
        return 'Threat reports';
    }

    public function page(): string
    {
        return 'threats.index';
    }

    public function canView(User $user): bool
    {
        return $user->can('viewAny', ThreatReport::class);
    }

    public function rules(User $user): array
    {
        return [
            // "any" shows every status; the default is active only.
            'status' => ['nullable', Rule::in([...array_map(fn (ThreatStatus $s) => $s->value, ThreatStatus::cases()), 'any'])],
            'threat_type' => ['nullable', 'string', 'size:26'],
            'credibility' => ['nullable', Rule::enum(ThreatCredibility::class)],
            'urgency' => ['nullable', Rule::enum(ThreatUrgency::class)],
            'state' => ['nullable', 'string', Rule::in($this->options->states($user)->pluck('ulid'))],
            'zone' => PlaceFilter::zoneRule($user),
            'lga' => ['nullable', 'string', 'size:26'],
            'expiring' => ['nullable', Rule::in(['1'])],
            // Records shared with the user by another jurisdiction (Phase 7A).
            'shared' => ['nullable', Rule::in(['1'])],
        ];
    }

    /**
     * The filtered threats without an order (the page adds its own).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<ThreatReport>
     */
    public function filtered(User $user, array $filters): Builder
    {
        $status = $filters['status'] ?? ThreatStatus::Active->value;
        $state = isset($filters['state']) ? $this->options->states($user)->firstWhere('ulid', $filters['state']) : null;
        $zone = PlaceFilter::zone($filters['zone'] ?? null);
        $lga = isset($filters['lga']) ? $this->options->lgas($user, $state)->firstWhere('ulid', $filters['lga']) : null;
        $threat = isset($filters['threat_type']) ? ThreatType::query()->where('ulid', $filters['threat_type'])->first() : null;

        return ThreatReport::query()
            ->when($status !== 'any', fn (Builder $q) => $q->where('threat_reports.status', $status))
            ->when($threat, fn (Builder $q) => $q->where('threat_reports.threat_type_id', $threat?->id))
            ->when($filters['credibility'] ?? null, fn (Builder $q, string $v) => $q->where('threat_reports.credibility', $v))
            ->when($filters['urgency'] ?? null, fn (Builder $q, string $v) => $q->where('threat_reports.urgency', $v))
            ->when($state, fn (Builder $q) => $q->where('threat_reports.state_id', $state?->id))
            ->tap(fn (Builder $q) => PlaceFilter::apply($q, 'threat_reports.state_id', $zone))
            ->when($lga, fn (Builder $q) => $q->where('threat_reports.lga_id', $lga?->id))
            ->when(isset($filters['expiring']), fn (Builder $q) => $q->active()
                ->where('threat_reports.expires_at', '<=', CarbonImmutable::now()->addHours((int) config('buffalo.threats.expiring_soon_hours'))))
            ->when(isset($filters['shared']), fn (Builder $q) => $this->disseminations->sharedOnly($q, $user));
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<ThreatReport>
     */
    public function query(User $user, array $filters): Builder
    {
        return $this->filtered($user, $filters)->withCoordinates()->byUrgency();
    }

    public function classificationColumn(): string
    {
        return 'threat_reports.classification';
    }

    public function with(): array
    {
        return ['threatType', 'state', 'lga', 'community'];
    }

    public function columns(): array
    {
        return [
            ListColumn::make('reference', 'Reference', fn (ThreatReport $t) => $t->reference_no),
            ListColumn::make('title', 'Title', fn (ThreatReport $t) => $t->title),
            ListColumn::make('threat_type', 'Threat type', fn (ThreatReport $t) => $t->threatType->name),
            ListColumn::make('credibility', 'Credibility', fn (ThreatReport $t) => $t->credibility->label()),
            ListColumn::make('urgency', 'Urgency', fn (ThreatReport $t) => $t->urgency->label()),
            ListColumn::make('status', 'Status', fn (ThreatReport $t) => $t->status->label()),
            ListColumn::make('source_type', 'Source type', fn (ThreatReport $t) => $t->source_type->label()),
            ListColumn::make('state', 'State', fn (ThreatReport $t) => $t->state->name),
            ListColumn::make('lga', 'LGA', fn (ThreatReport $t) => $t->lga->name),
            ListColumn::make('community', 'Community', fn (ThreatReport $t) => $t->community?->name),
            ListColumn::make('latitude', 'Latitude', fn (ThreatReport $t) => $t->latitude === null ? null : round((float) $t->latitude, 6)),
            ListColumn::make('longitude', 'Longitude', fn (ThreatReport $t) => $t->longitude === null ? null : round((float) $t->longitude, 6)),
            ListColumn::make('expected_from', 'Expected from', fn (ThreatReport $t) => self::at($t->expected_from)),
            ListColumn::make('expected_until', 'Expected until', fn (ThreatReport $t) => self::at($t->expected_until)),
            ListColumn::make('expires_at', 'Expires', fn (ThreatReport $t) => self::at($t->expires_at)),
            ListColumn::make('created_at', 'Reported', fn (ThreatReport $t) => self::at($t->created_at)),
            ListColumn::make('description', 'Description', fn (ThreatReport $t) => self::text($t, 'description')),
            ListColumn::make('target', 'Target', fn (ThreatReport $t) => self::text($t, 'target_description')),
            ListColumn::make('recommended_action', 'Recommended action', fn (ThreatReport $t) => self::text($t, 'recommended_action')),
            ListColumn::make('classification', 'Classification', fn (ThreatReport $t) => $t->classificationLevel()->label()),
        ];
    }

    public function defaultColumns(): array
    {
        return ['reference', 'title', 'threat_type', 'credibility', 'urgency', 'status', 'lga', 'state', 'expires_at', 'classification'];
    }
}
