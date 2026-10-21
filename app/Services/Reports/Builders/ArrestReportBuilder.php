<?php

namespace App\Services\Reports\Builders;

use App\Enums\AccessAction;
use App\Http\Presenters\ArrestPresenter;
use App\Models\Arrest;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * One arrest as a PDF (Phase 11E), as its page shows it: details, arrested
 * persons (named only when the requester can see them), offences, the
 * incident comparison and the status timeline.
 */
final class ArrestReportBuilder implements ReportBuilder
{
    public function __construct(private readonly PdfRenderer $pdf) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $arrest = Arrest::query()->withCoordinates()->where('ulid', (string) $run->parameters['arrest'])->first();

        if ($arrest === null || ! $user->can('view', $arrest)) {
            throw new AuthorizationException('The arrest is no longer visible to the requester.');
        }

        $arrest->recordAccess(AccessAction::Print, 'report');
        $arrest->load(['agency', 'formation', 'lga', 'state', 'community', 'incident']);
        $levels = new LevelTracker;
        $levels->see($arrest->classificationLevel());

        return $this->pdf->render('reports.arrest', [
            'arrest' => ArrestPresenter::detail($arrest),
            'persons' => ArrestPresenter::persons($arrest, $user),
            'comparison' => $arrest->incident !== null ? ArrestPresenter::comparison($arrest->incident) : null,
            'timeline' => ArrestPresenter::timeline($arrest),
        ], 'Arrest '.$arrest->reference_no, $run, $user, $levels);
    }
}
