<?php

namespace App\Actions\Dashboard;

use App\Models\Community;
use App\Models\Lga;
use App\Models\LgaProfile;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Dashboard\PlaceholderCards;
use App\Support\Jurisdiction\JurisdictionLabel;
use App\Support\LocalGovernment\ProfileCompleteness;

/**
 * LGA Security Chief dashboard: their LGA's details.
 */
final class BuildLgaDashboard
{
    /**
     * @return array<string, mixed>
     */
    public function __invoke(User $user): array
    {
        /** @var Lga $lga */
        $lga = $user->lga;
        $label = $lga->name.' LGA';

        return [
            'lga' => [
                'ulid' => $lga->ulid,
                'name' => $lga->name,
                'label' => $label,
                'state' => JurisdictionLabel::state($lga->state),
                'senatorialDistrict' => $lga->senatorialDistrict->name ?? null,
                'headquarters' => $lga->headquarters,
                'areaKm2' => $lga->area_km2,
                'populationEstimate' => $lga->population_estimate,
            ],
            'profile' => $this->profile($lga),
            'map' => MapView::for($lga),
            'placeholders' => PlaceholderCards::for($label),
        ];
    }

    /**
     * Profile completeness and communities (read through the scope).
     *
     * @return array{completeness: int, missing: list<string>, communities: int, last_reviewed_at: string|null}
     */
    private function profile(Lga $lga): array
    {
        $profile = LgaProfile::query()->firstWhere('lga_id', $lga->id);
        $communities = Community::query()->where('lga_id', $lga->id)->count();

        return [
            'completeness' => ProfileCompleteness::percent($profile, $communities),
            'missing' => array_map(fn (string $item): string => ProfileCompleteness::ITEMS[$item], ProfileCompleteness::missing($profile, $communities)),
            'communities' => $communities,
            'last_reviewed_at' => $profile?->last_reviewed_at?->toIso8601String(),
        ];
    }
}
