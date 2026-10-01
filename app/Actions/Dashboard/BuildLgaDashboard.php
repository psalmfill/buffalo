<?php

namespace App\Actions\Dashboard;

use App\Enums\SecurityNoteStatus;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\LgaProfile;
use App\Models\PersonOfInterest;
use App\Models\ReligiousInstitution;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Dashboard\AccessFigures;
use App\Support\Dashboard\CoverageFigures;
use App\Support\Dashboard\DisseminationFigures;
use App\Support\Dashboard\IncidentFigures;
use App\Support\Dashboard\MapView;
use App\Support\Dashboard\OrganizationFigures;
use App\Support\Dashboard\PlaceholderCards;
use App\Support\Dashboard\ThreatFigures;
use App\Support\Dashboard\ThreatReportFigures;
use App\Support\Jurisdiction\JurisdictionLabel;
use App\Support\LocalGovernment\ProfileCompleteness;

/**
 * LGA Security Chief dashboard: their LGA's details.
 */
final class BuildLgaDashboard
{
    public function __construct(private readonly ThreatFigures $figures) {}

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
            // Through the jurisdiction and classification scopes.
            'information' => [
                'activeSecurityNotes' => CommunitySecurityNote::query()->where('lga_id', $lga->id)->where('status', SecurityNoteStatus::Active->value)->count(),
                'religiousInstitutions' => ReligiousInstitution::query()->where('lga_id', $lga->id)->count(),
            ],
            'threats' => $user->can('viewAny', PersonOfInterest::class) ? [
                'poisByRisk' => $this->figures->poisByRisk($lga->id),
                'overdueReviews' => $this->figures->overdueReviews($lga->id),
            ] : null,
            'access' => app(AccessFigures::class)->for($user),
            'organizations' => app(OrganizationFigures::class)->forLga($user, $lga),
            'coverage' => app(CoverageFigures::class)->forLga($user, $lga),
            'map' => MapView::for($lga),
            'incidents' => $user->can('viewAny', Incident::class) ? app(IncidentFigures::class)->forLga($user, $lga) : null,
            // Threat reports (Phase 6B), through their own scopes.
            'threatReports' => $user->can('viewAny', ThreatReport::class) ? app(ThreatReportFigures::class)->forLga($lga) : null,
            // Dissemination (Phase 7A).
            'sharedWithUs' => app(DisseminationFigures::class)->unacknowledged($user),
            'placeholders' => PlaceholderCards::for($label, $user),
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
