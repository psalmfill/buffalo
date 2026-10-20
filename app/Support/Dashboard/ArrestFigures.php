<?php

namespace App\Support\Dashboard;

use App\Http\Presenters\ArrestPresenter;
use App\Models\Arrest;
use App\Models\ArrestPersonOffence;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Arrests on the dashboards (Phase 11C), through the viewer's scopes. Every
 * arrest counts, including arrests linked to an incident later marked
 * invalid: the arrest itself happened (the statistics rule is about
 * incidents).
 */
final class ArrestFigures
{
    public const DAYS = 30;

    /**
     * @return array<string, mixed>|null
     */
    public function forLga(User $user, Lga $lga): ?array
    {
        if (! $user->can('viewAny', Arrest::class)) {
            return null;
        }

        $recent = fn (): Builder => $this->recent()->where('arrests.lga_id', $lga->id);

        return [
            'count' => $recent()->count(),
            'days' => self::DAYS,
            'latest' => array_values($recent()->with(['agency', 'lga', 'incident', 'persons.offences.offenceType'])
                ->orderByDesc('arrests.arrested_at')->limit(5)->get()
                ->map(fn (Arrest $a) => ArrestPresenter::row($a))->all()),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forState(User $user, State $state): ?array
    {
        if (! $user->can('viewAny', Arrest::class)) {
            return null;
        }

        $arrests = fn (): Builder => $this->recent()->where('arrests.state_id', $state->id);

        $byAgency = $arrests()->join('security_agencies', 'security_agencies.id', '=', 'arrests.security_agency_id')
            ->toBase()->selectRaw('security_agencies.name AS label, count(*) AS total')
            ->groupBy('security_agencies.name')->orderByDesc('total')->orderBy('label')->limit(10)->get()
            ->map(fn (object $r) => ['label' => (string) $r->label, 'count' => (int) $r->total])->values()->all();

        // Arrested persons per offence (one person may have several).
        $byOffence = ArrestPersonOffence::query()
            ->whereHas('arrestPerson.arrest', fn (Builder $q) => $q->where('arrests.state_id', $state->id)->where('arrests.arrested_at', '>=', CarbonImmutable::now()->subDays(self::DAYS)))
            ->join('offence_types', 'offence_types.id', '=', 'arrest_person_offences.offence_type_id')
            ->toBase()->selectRaw('offence_types.name AS label, count(*) AS total')
            ->groupBy('offence_types.name')->orderByDesc('total')->orderBy('label')->limit(10)->get()
            ->map(fn (object $r) => ['label' => (string) $r->label, 'count' => (int) $r->total])->values()->all();

        return ['count' => $arrests()->count(), 'days' => self::DAYS, 'byAgency' => $byAgency, 'byOffence' => $byOffence];
    }

    /**
     * Arrests in the last 30 days per state (the national state grid).
     *
     * @param  list<int>  $stateIds
     * @return array<int, int>
     */
    public function perState(array $stateIds): array
    {
        return $this->recent()->whereIn('arrests.state_id', $stateIds)->toBase()
            ->selectRaw('arrests.state_id AS state, count(*) AS total')->groupBy('arrests.state_id')
            ->pluck('total', 'state')->map(fn ($n) => (int) $n)->all();
    }

    /**
     * @return Builder<Arrest>
     */
    private function recent(): Builder
    {
        return Arrest::query()->where('arrests.arrested_at', '>=', CarbonImmutable::now()->subDays(self::DAYS));
    }
}
