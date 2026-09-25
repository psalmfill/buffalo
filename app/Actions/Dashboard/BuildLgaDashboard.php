<?php

namespace App\Actions\Dashboard;

use App\Models\Lga;
use App\Models\User;
use App\Support\Dashboard\MapView;
use App\Support\Dashboard\PlaceholderCards;
use App\Support\Jurisdiction\JurisdictionLabel;

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
            'map' => MapView::for($lga),
            'placeholders' => PlaceholderCards::for($label),
        ];
    }
}
