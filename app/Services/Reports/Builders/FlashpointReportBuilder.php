<?php

namespace App\Services\Reports\Builders;

use App\Enums\AccessAction;
use App\Http\Presenters\DisseminationPresenter;
use App\Http\Presenters\FlashpointPresenter;
use App\Models\CommunitySecurityNote;
use App\Models\Flashpoint;
use App\Models\FlashpointAssessment;
use App\Models\Incident;
use App\Models\ReportRun;
use App\Models\ThreatReport;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use App\Support\Dashboard\MapView;
use App\Support\Flashpoints\FlashpointLinks;
use App\Support\Jurisdiction\DisseminatedLinks;
use App\Support\Reports\LocationSketch;
use App\Support\Threats\ThreatLinks;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * One flashpoint or blackspot as a PDF (Phase 11E): the header facts, a
 * location sketch drawn on the server (boundary, point and radius, linked
 * incidents; no map tiles), description and recommended action, the
 * current assessment and the full history, and the linked incidents,
 * security notes and threat reports the requester may see. Disseminations
 * appear for the owning jurisdiction only, as on the page. The level is the
 * highest classification among everything included.
 */
final class FlashpointReportBuilder implements ReportBuilder
{
    public function __construct(private readonly PdfRenderer $pdf, private readonly DisseminationPresenter $disseminations) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $flashpoint = Flashpoint::query()->withCoordinates()->where('ulid', (string) $run->parameters['flashpoint'])->first();

        if ($flashpoint === null || ! $user->can('view', $flashpoint)) {
            throw new AuthorizationException('The flashpoint is no longer visible to the requester.');
        }

        $flashpoint->recordAccess(AccessAction::Print, 'report');
        $flashpoint->load(['threatType', 'lga', 'state', 'community']);
        $levels = new LevelTracker;
        $levels->see($flashpoint->classificationLevel());

        $assessments = FlashpointPresenter::assessments(
            DisseminatedLinks::of(FlashpointAssessment::query()->where('flashpoint_id', $flashpoint->id), $flashpoint)->latest('assessed_at')->latest('id')->get(),
        );
        $incidents = $user->can('viewAny', Incident::class) ? FlashpointLinks::incidents($flashpoint) : null;
        $notes = $user->can('viewAny', CommunitySecurityNote::class) ? FlashpointLinks::securityNotes($flashpoint) : null;
        $threats = $user->can('viewAny', ThreatReport::class) ? ThreatLinks::forFlashpoint($flashpoint) : null;
        foreach ([$incidents, $notes, $threats] as $rows) {
            $levels->seeAll(array_map(fn (array $row) => $row['classification']['value'] ?? null, $rows ?? []));
        }

        $markers = $incidents === null ? [] : FlashpointLinks::incidentMarkers($flashpoint);
        $sketch = $flashpoint->latitude === null || $flashpoint->longitude === null ? null : (new LocationSketch(
            MapView::outline($flashpoint->lga),
            (float) $flashpoint->latitude,
            (float) $flashpoint->longitude,
            $flashpoint->radius_meters,
            array_map(fn (array $m) => [$m['latitude'], $m['longitude']], $markers),
        ))->svg();

        return $this->pdf->render('reports.flashpoint', [
            'flashpoint' => FlashpointPresenter::detail($flashpoint),
            'sketch' => $sketch,
            'markers' => count($markers),
            'assessments' => $assessments,
            'incidents' => $incidents,
            'notes' => $notes,
            'threats' => $threats,
            // Owners only (null for recipients of a dissemination), as on the page.
            'disseminations' => $this->disseminations->sharing($flashpoint, $user)['disseminations'] ?? null,
        ], $flashpoint->kind->label().' — '.$flashpoint->name, $run, $user, $levels);
    }
}
