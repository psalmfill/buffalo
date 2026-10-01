<?php

namespace App\Support\Dashboard;

use App\Enums\ThreatStatus;
use App\Enums\ThreatUrgency;
use App\Http\Presenters\ThreatReportPresenter;
use App\Models\Lga;
use App\Models\State;
use App\Models\ThreatReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Threat report figures for the dashboards (Phase 6B). Everything goes
 * through the viewer's scopes, so counts never include reports they could
 * not open. Live updates come over the threat channels.
 */
final class ThreatReportFigures
{
    private const LIST = 10;

    /**
     * @return array<string, mixed>
     */
    public function forLga(Lga $lga): array
    {
        return [
            'active' => $this->rows(ThreatReport::query()->active()->where('threat_reports.lga_id', $lga->id), 20),
            'channel' => 'states.'.$lga->state->ulid.'.threats',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forState(State $state): array
    {
        $base = fn (): Builder => ThreatReport::query()->where('threat_reports.state_id', $state->id);
        $counts = $base()->active()->groupBy('threat_reports.urgency')
            ->selectRaw('threat_reports.urgency AS urgency, count(*) AS total')->toBase()->pluck('total', 'urgency');

        return [
            'byUrgency' => array_map(fn (ThreatUrgency $u) => [...ThreatReportPresenter::option($u), 'total' => (int) ($counts[$u->value] ?? 0)], array_reverse(ThreatUrgency::cases())),
            'expiringSoon' => $this->rows($base()->active()->where('threat_reports.expires_at', '<=', CarbonImmutable::now()->addHours((int) config('buffalo.threats.expiring_soon_hours')))->reorder()->orderBy('threat_reports.expires_at'), self::LIST),
            'recentlyMaterialised' => $this->rows($base()->where('threat_reports.status', ThreatStatus::Materialised->value)
                ->where('threat_reports.updated_at', '>=', CarbonImmutable::now()->subDays(14))->reorder()->latest('threat_reports.updated_at'), self::LIST),
            'channel' => 'states.'.$state->ulid.'.threats',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function national(): array
    {
        $byStateAndType = ThreatReport::query()->active()
            ->join('states', 'states.id', '=', 'threat_reports.state_id')
            ->join('threat_types', 'threat_types.id', '=', 'threat_reports.threat_type_id')
            ->groupBy('states.name', 'threat_types.name')
            ->selectRaw('states.name AS state, threat_types.name AS type, count(*) AS total')
            ->orderBy('states.name')->orderBy('threat_types.name')
            ->toBase()->get();

        return [
            'immediate' => $this->rows(ThreatReport::query()->active()->where('threat_reports.urgency', ThreatUrgency::Immediate->value), 50),
            'byStateAndType' => array_values($byStateAndType->map(fn (object $r) => ['state' => (string) $r->state, 'type' => (string) $r->type, 'total' => (int) $r->total])->all()),
            'channel' => 'national.threats',
        ];
    }

    /**
     * @param  Builder<ThreatReport>  $query
     * @return list<array<string, mixed>>
     */
    private function rows(Builder $query, int $limit): array
    {
        $query = $query->getQuery()->orders === null || $query->getQuery()->orders === [] ? $query->byUrgency() : $query;

        return array_values($query->withCoordinates()->with(['threatType', 'lga', 'state'])->limit($limit)->get()
            ->map(fn (ThreatReport $t) => ThreatReportPresenter::row($t))->all());
    }
}
