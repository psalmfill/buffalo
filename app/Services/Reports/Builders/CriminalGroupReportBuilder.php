<?php

namespace App\Services\Reports\Builders;

use App\Enums\AccessAction;
use App\Http\Presenters\CriminalGroupPresenter;
use App\Http\Presenters\IncidentRows;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupArea;
use App\Models\CriminalGroupMember;
use App\Models\Incident;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use App\Support\Incidents\IncidentLinks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * One criminal group as a PDF (Phase 11E): details, areas of operation,
 * members only as the requester can see them (names and roles, never
 * contact details or photos), and linked incidents the requester can see.
 */
final class CriminalGroupReportBuilder implements ReportBuilder
{
    /** Linked incidents printed at most (the newest). */
    private const INCIDENTS = 200;

    public function __construct(private readonly PdfRenderer $pdf) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $group = CriminalGroup::query()->where('ulid', (string) $run->parameters['group'])->first();

        if ($group === null || ! $user->can('view', $group)) {
            throw new AuthorizationException('The criminal group is no longer visible to the requester.');
        }

        $group->recordAccess(AccessAction::Print, 'report');
        $group->load(['lga', 'state'])->loadCount(['members as visible_members_count' => fn (Builder $q) => $q->whereHas('person')]);
        $levels = new LevelTracker;
        $levels->see($group->classificationLevel());

        $members = CriminalGroupMember::query()->where('criminal_group_id', $group->id)->withVisiblePerson()
            ->with(['person.lga', 'person.roles'])
            ->orderByRaw("CASE membership_status WHEN 'confirmed' THEN 1 WHEN 'suspected' THEN 2 ELSE 3 END")
            ->get();
        $levels->seeAll($members->map(fn (CriminalGroupMember $m) => $m->person?->classificationLevel()));

        $incidents = $user->can('viewAny', Incident::class)
            ? IncidentRows::present(IncidentLinks::group($group)->with(['type', 'lga', 'state'])
                ->orderByDesc('incidents.occurred_at')->orderByDesc('incidents.id')->limit(self::INCIDENTS)->get())
            : null;
        $levels->seeAll(array_map(fn (array $row) => $row['classification']['value'] ?? null, $incidents ?? []));

        $areas = CriminalGroupArea::query()->where('criminal_group_id', $group->id)->with(['lga', 'state'])
            ->join('lgas', 'lgas.id', '=', 'criminal_group_areas.lga_id')->select('criminal_group_areas.*')->orderBy('lgas.name')->get();

        return $this->pdf->render('reports.criminal-group', [
            'group' => CriminalGroupPresenter::detail($group),
            'areas' => $areas->map(fn (CriminalGroupArea $a) => CriminalGroupPresenter::area($a))->all(),
            'members' => $members->map(fn (CriminalGroupMember $m) => [
                'name' => $m->person?->name,
                'lga' => $m->person?->lga->name,
                'role' => $m->role_in_group,
                'status' => $m->membership_status->label(),
                'since' => $m->since?->format('j M Y'),
            ])->all(),
            'incidents' => $incidents,
        ], 'Criminal group — '.$group->name, $run, $user, $levels);
    }
}
