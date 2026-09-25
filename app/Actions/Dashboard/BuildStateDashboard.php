<?php

namespace App\Actions\Dashboard;

use App\Enums\Role;
use App\Models\Lga;
use App\Models\OfficerPosting;
use App\Models\State;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Dashboard\PlaceholderCards;
use App\Support\Jurisdiction\JurisdictionLabel;
use App\Support\LocalGovernment\ProfileCompleteness;

/**
 * State Director and Deputy State Director dashboard: the state's LGAs with
 * their current LGA Security Chief (from open officer postings) and profile
 * completeness, least complete first (all read through the jurisdiction
 * scope).
 */
final class BuildStateDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user): array
    {
        /** @var State $state */
        $state = $user->state;
        $label = JurisdictionLabel::state($state);

        $lgas = Lga::query()->active()->where('state_id', $state->id)->orderBy('name')->get();

        $chiefs = OfficerPosting::query()
            ->with('user')
            ->where('role', Role::LgaSecurityChief->value)
            ->whereNull('ended_at')
            ->whereIn('lga_id', $lgas->modelKeys())
            ->orderBy('started_at')
            ->get()
            // The latest posting wins if an LGA ever has two open ones.
            ->keyBy('lga_id');

        $stats = ProfileCompleteness::forLgas($lgas);

        return [
            'state' => [
                'ulid' => $state->ulid,
                'name' => $state->name,
                'label' => $label,
                'zone' => $state->geopoliticalZone->name ?? null,
            ],
            // Least complete profiles first: where work is needed.
            'lgas' => $lgas->map(function (Lga $lga) use ($chiefs, $stats): array {
                $chief = $chiefs->get($lga->id)?->user;
                $lgaStats = $stats->get($lga->id);

                return [
                    'ulid' => $lga->ulid,
                    'name' => $lga->name,
                    'headquarters' => $lga->headquarters,
                    'chief' => $chief === null ? null : ['ulid' => $chief->ulid, 'name' => $chief->name],
                    'completeness' => $lgaStats['completeness'] ?? 0,
                    'last_reviewed_at' => ($lgaStats['last_reviewed_at'] ?? null)?->toIso8601String(),
                ];
            })->sortBy([['completeness', 'asc'], ['name', 'asc']])->values()->all(),
            'map' => MapView::for($state),
            'placeholders' => PlaceholderCards::for($label),
        ];
    }
}
