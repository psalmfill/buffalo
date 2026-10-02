<?php

namespace App\Services\Reports\Builders;

use App\Enums\FlashpointStatus;
use App\Enums\FormationStatus;
use App\Enums\PoliticalOfficeType;
use App\Http\Presenters\LgaPresenter;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\LgaProfile;
use App\Models\PoliticalOffice;
use App\Models\ReligiousInstitution;
use App\Models\ReportRun;
use App\Models\User;
use App\Models\VulnerablePopulationStat;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use App\Support\CommunityInformation\TabData;
use App\Support\Incidents\IncidentLinks;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * An LGA profile as a PDF (Phase 7D): facts and profile, communities
 * summary, religious institutions summary (counts), the latest LGA-wide
 * vulnerable population figures (aggregates only), security presence
 * (formations, without commanders' names), current public office holders
 * (LGA chairman, vice chairman and councillors only), flashpoints and a
 * 90-day incident summary. No persons of interest, criminal group members,
 * traditional or religious leaders: the only person names are current
 * public office holders (CLAUDE.md). Each section is read through its own
 * scopes and appears only for modules the requester may view.
 */
final class LgaProfileBuilder implements ReportBuilder
{
    public function __construct(private readonly PdfRenderer $pdf, private readonly TabData $tabs) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $lga = Lga::query()->where('ulid', (string) $run->parameters['lga'])->first();

        if ($lga === null || ! $user->can('view', $lga)) {
            throw new AuthorizationException('The LGA is no longer within the requester\'s jurisdiction.');
        }

        $lga->loadMissing(['state', 'senatorialDistrict']);
        $levels = new LevelTracker;
        $communities = Community::query()->where('lga_id', $lga->id);
        $data = [
            'facts' => LgaPresenter::facts($lga),
            'profile' => LgaPresenter::profile(LgaProfile::query()->with('reviewer')->firstWhere('lga_id', $lga->id)),
            'communities' => ['count' => (clone $communities)->count(), 'wards' => (clone $communities)->whereNotNull('ward_id')->distinct()->count('ward_id')],
        ];

        if ($user->can('viewAny', ReligiousInstitution::class)) {
            $data['religious'] = ReligiousInstitution::query()->where('religious_institutions.lga_id', $lga->id)
                ->join('religions', 'religions.id', '=', 'religious_institutions.religion_id')
                ->groupBy('religions.name')->orderBy('religions.name')
                ->get([DB::raw('religions.name AS religion'), DB::raw('COUNT(*) AS institutions'), DB::raw('SUM(religious_institutions.estimated_congregation) AS congregation')])
                ->map(fn (ReligiousInstitution $r) => ['religion' => (string) $r->getAttribute('religion'), 'institutions' => (int) $r->getAttribute('institutions'), 'congregation' => $r->getAttribute('congregation') === null ? null : (int) $r->getAttribute('congregation')])
                ->all();
        }

        if ($user->can('viewAny', VulnerablePopulationStat::class)) {
            $data['vulnerable'] = $this->tabs->latestFigures($lga, null)->map(fn (VulnerablePopulationStat $f) => [
                'category' => $f->category->label(), 'adult_male' => $f->adult_male, 'adult_female' => $f->adult_female,
                'child_male' => $f->child_male, 'child_female' => $f->child_female,
                'total' => $f->adult_male + $f->adult_female + $f->child_male + $f->child_female,
                'as_of' => $f->as_of_date->format('j M Y'),
            ])->values()->all();
        }

        if ($user->can('viewAny', AgencyFormation::class)) {
            $formations = AgencyFormation::query()->with('agency')->where('lga_id', $lga->id)
                ->where('status', '<>', FormationStatus::Closed->value)->orderBy('name')->get();
            $levels->seeAll($formations->map->classificationLevel());
            $data['formations'] = $formations->map(fn (AgencyFormation $f) => [
                'agency' => $f->agency->short_name ?: $f->agency->name, 'name' => $f->name,
                'type' => $f->formation_type->label(), 'status' => $f->status->label(),
            ])->all();
        }

        if ($user->can('viewAny', PoliticalOffice::class)) {
            $order = [PoliticalOfficeType::LgaChairman->value => 0, PoliticalOfficeType::LgaViceChairman->value => 1, PoliticalOfficeType::Councillor->value => 2];
            $data['offices'] = PoliticalOffice::query()->current()->with(['person', 'ward'])->where('lga_id', $lga->id)
                ->whereIn('office', array_keys($order))->get()
                ->sortBy(fn (PoliticalOffice $o) => ($order[$o->office->value] ?? 9).' '.($o->ward->name ?? ''))
                ->map(fn (PoliticalOffice $o) => [
                    'office' => $o->title(), 'ward' => $o->ward?->name, 'party' => $o->party,
                    'name' => $o->person->name ?? 'Not recorded', 'since' => $o->started_on->format('j M Y'),
                ])->values()->all();
        }

        if ($user->can('viewAny', Flashpoint::class)) {
            $flashpoints = Flashpoint::query()->where('flashpoints.lga_id', $lga->id)
                ->where('flashpoints.status', '<>', FlashpointStatus::Resolved->value)->byConcern()->get();
            $levels->seeAll($flashpoints->map->classificationLevel());
            $data['flashpoints'] = $flashpoints->map(fn (Flashpoint $f) => [
                'name' => $f->name, 'kind' => $f->kind->label(), 'concern' => $f->concern_level->label(), 'status' => $f->status->label(),
            ])->all();
        }

        if ($user->can('viewAny', Incident::class)) {
            $levels->seeAll(IncidentLinks::lga($lga)->where('incidents.occurred_at', '>=', now()->subDays(90))->toBase()->distinct()->pluck('incidents.classification'));
            $data['incidents'] = IncidentLinks::lgaSummary($lga);
        }

        return $this->pdf->render('reports.lga-profile', $data, $lga->name.' LGA profile', $run, $user, $levels);
    }
}
