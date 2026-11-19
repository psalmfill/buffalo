<?php

namespace App\Services\Reports\Builders;

use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Http\Presenters\TaskingPresenter;
use App\Models\ReportRun;
use App\Models\Tasking;
use App\Models\TaskingRecipient;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use App\Support\Taskings\LgaChiefs;
use App\Support\Taskings\TaskingProgress;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * One sent tasking as a PDF (Phase 11H): the instruction, follow-up notes
 * and attachment list, and:
 *
 * - for directors and national users, the recipients table with progress;
 * - for an LGA chief, their own LGA's status and report back only (never
 *   other LGAs, their responses or the target mode).
 *
 * Built as the requester, through their scopes, like every record PDF.
 */
final class TaskingReportBuilder implements ReportBuilder
{
    public function __construct(private readonly PdfRenderer $pdf, private readonly LgaChiefs $chiefs) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $tasking = Tasking::query()->with('state')->where('ulid', (string) $run->parameters['tasking'])->first();

        if ($tasking === null || $tasking->isDraft() || ! $user->can('view', $tasking)) {
            throw new AuthorizationException('The tasking is no longer visible to the requester.');
        }

        $tasking->recordAccess(AccessAction::Print, 'report');
        $levels = new LevelTracker;
        $levels->see($tasking->classificationLevel());
        $seesAll = $user->can('viewRecipients', $tasking);
        $mine = $seesAll ? null : TaskingRecipient::query()->where('tasking_id', $tasking->id)->where('lga_id', $user->lga_id)->first();
        $attachments = TaskingPresenter::taskingAttachments($tasking, $user);

        foreach ($attachments as $a) {
            $levels->see(Classification::from($a['classification']['value']));
        }

        $progress = $seesAll ? (TaskingProgress::for([$tasking->id])[$tasking->id] ?? null) : null;

        return $this->pdf->render('reports.tasking', [
            'tasking' => TaskingPresenter::detail($tasking, ! $seesAll),
            'state' => $tasking->state->name,
            'notes' => TaskingPresenter::notes($tasking),
            'attachments' => $attachments,
            'progress' => $progress === null ? null : TaskingProgress::line($progress),
            'recipients' => $seesAll ? TaskingPresenter::recipients($tasking, $this->chiefs->forLgas(
                TaskingRecipient::query()->where('tasking_id', $tasking->id)->pluck('lga_id')->all(),
            )) : null,
            'mine' => $mine === null ? null : TaskingPresenter::recipient($tasking, $mine, $user),
        ], 'Tasking '.$tasking->reference_no, $run, $user, $levels);
    }
}
