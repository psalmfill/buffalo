<?php

namespace App\Actions\Dashboard;

use App\Enums\JurisdictionLevel;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Dashboard\PlaceholderCards;
use App\Support\Jurisdiction\JurisdictionRules;
use App\Support\Jurisdiction\RestrictionType;
use App\Support\LocalGovernment\ProfileCompleteness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Director General and Deputy Director General dashboard. A zoned DDG's
 * counts cover only their zones (JurisdictionRules).
 */
final class BuildNationalDashboard
{
    public function __construct(private readonly JurisdictionRules $rules) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user): array
    {
        $restriction = $this->rules->restrictionFor($user, visibleToSuperAdmin: false);

        /** @var Builder<State> $states */
        $states = State::query()->active()
            ->when($restriction->type !== RestrictionType::Everything, fn (Builder $query) => $query->whereIn('id', $restriction->stateIds));

        $label = $user->jurisdictionLabel();

        return [
            'scope' => [
                'label' => $label,
                'zones' => $user->jurisdiction_level === JurisdictionLevel::National
                    ? $user->zones->sortBy('name')->pluck('name')->values()->all()
                    : [],
                'states' => (clone $states)->count(),
                'lgas' => Lga::query()->active()->whereIn('state_id', (clone $states)->select('id'))->count(),
            ],
            'completenessByState' => $this->completenessByState((clone $states)->orderBy('name')->get()),
            'map' => MapView::for(null),
            'placeholders' => PlaceholderCards::for($label),
        ];
    }

    /**
     * Average LGA profile completeness per state, lowest first.
     *
     * @param  Collection<int, State>  $states
     * @return list<array{state: string, lgas: int, average: int}>
     */
    private function completenessByState(Collection $states): array
    {
        $lgas = Lga::query()->active()->whereIn('state_id', $states->modelKeys())->get(['id', 'state_id']);
        $stats = ProfileCompleteness::forLgas($lgas);

        return array_values($states
            ->map(function (State $state) use ($lgas, $stats): array {
                $scores = $lgas->where('state_id', $state->id)->map(fn (Lga $lga): int => $stats[$lga->id]['completeness']);

                return [
                    'state' => $state->name,
                    'lgas' => $scores->count(),
                    'average' => $scores->isEmpty() ? 0 : (int) round($scores->avg()),
                ];
            })
            ->sortBy([['average', 'asc'], ['state', 'asc']])
            ->all());
    }
}
