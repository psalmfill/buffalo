<?php

namespace App\Services\Reports\Builders;

use App\Enums\AccessAction;
use App\Http\Presenters\DisseminationPresenter;
use App\Http\Presenters\ThreatReportPresenter;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\ReportRun;
use App\Models\ThreatReport;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use App\Support\Threats\ThreatLinks;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * One threat report as a PDF (Phase 11E), as its page shows it: details,
 * the flashpoints, criminal groups and incidents linked to it that the
 * requester may see, the status timeline, and (for the owning jurisdiction
 * only) its disseminations.
 */
final class ThreatReportBuilder implements ReportBuilder
{
    public function __construct(private readonly PdfRenderer $pdf, private readonly DisseminationPresenter $disseminations) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $threat = ThreatReport::query()->withCoordinates()->where('ulid', (string) $run->parameters['threat'])->first();

        if ($threat === null || ! $user->can('view', $threat)) {
            throw new AuthorizationException('The threat report is no longer visible to the requester.');
        }

        $threat->recordAccess(AccessAction::Print, 'report');
        $threat->load(['threatType', 'lga', 'state', 'community']);
        $levels = new LevelTracker;
        $levels->see($threat->classificationLevel());

        $flashpoints = $user->can('viewAny', Flashpoint::class) ? ThreatLinks::flashpoints($threat) : null;
        $groups = $user->can('viewAny', CriminalGroup::class) ? ThreatLinks::groups($threat) : null;
        $incidents = $user->can('viewAny', Incident::class) ? ThreatLinks::incidents($threat) : null;
        foreach ([$flashpoints, $groups, $incidents] as $rows) {
            $levels->seeAll(array_map(fn (array $row) => $row['classification']['value'] ?? null, $rows ?? []));
        }

        return $this->pdf->render('reports.threat', [
            'threat' => ThreatReportPresenter::detail($threat),
            'flashpoints' => $flashpoints,
            'groups' => $groups,
            'incidents' => $incidents,
            'timeline' => ThreatReportPresenter::timeline($threat),
            'disseminations' => $this->disseminations->sharing($threat, $user)['disseminations'] ?? null,
        ], 'Threat report '.$threat->reference_no.' — '.$threat->title, $run, $user, $levels);
    }
}
