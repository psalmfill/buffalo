<?php

namespace App\Services\Reports\Builders;

use App\Enums\CasualtyOutcome;
use App\Enums\IncidentStatus;
use App\Enums\PersonInvolvement;
use App\Models\Incident;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportMarkings;
use App\Services\Reports\ReportOutput;
use App\Support\Incidents\IncidentFilters;
use App\Support\Jurisdiction\OfficerNames;
use App\Support\Reports\SpreadsheetWriter;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Incident list export (Phase 7D): any IncidentFilters selection through
 * the requester's scopes (drafts and invalid reports never included), up to
 * `buffalo.reports.max_rows` rows, as XLSX or CSV.
 *
 * No person names (persons are counted by involvement only), no evidence,
 * and no free-text descriptions: descriptions, locations and notes are
 * free text that can name persons, so the bulk file leaves them out. The
 * scopes already apply the classification ceiling to every row.
 *
 * The classification of the file is the highest level among its rows, so
 * the marking rows are written after a first pass over the levels.
 */
final class IncidentListBuilder implements ReportBuilder
{
    private const CHUNK = 1000;

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $max = (int) config('buffalo.reports.max_rows');
        $query = self::query($user, (array) ($run->parameters['filters'] ?? []));
        $rows = (clone $query)->count();

        if ($rows > $max) {
            throw new RuntimeException("Incident export of {$rows} rows exceeds the limit of {$max}.");
        }

        $levels = new LevelTracker;
        $levels->seeAll((clone $query)->toBase()->distinct()->pluck('incidents.classification'));
        $markings = ReportMarkings::for($run, $user, $levels->level());
        $path = tempnam(sys_get_temp_dir(), 'buffalo-report-').'.'.$run->format->value;
        $writer = new SpreadsheetWriter($run->format->value, $path, $markings->rows('Incident list'), 'Incident list');
        $writer->header(self::headings());

        $query->with(['type', 'state', 'lga', 'community'])->chunkById(self::CHUNK, function (Collection $incidents) use ($writer): void {
            $ids = $incidents->pluck('id')->all();
            $casualties = DB::table('incident_casualties')->whereIn('incident_id', $ids)->whereNull('deleted_at')
                ->selectRaw('incident_id, outcome, sum(adult_male + adult_female + child_male + child_female + "unknown") AS people')
                ->groupBy('incident_id', 'outcome')->get()->groupBy('incident_id');
            $persons = DB::table('incident_persons')->whereIn('incident_id', $ids)->whereNull('deleted_at')
                ->selectRaw('incident_id, involvement, count(*) AS people')
                ->groupBy('incident_id', 'involvement')->get()->groupBy('incident_id');
            $agencies = DB::table('incident_agencies')->join('security_agencies', 'security_agencies.id', '=', 'incident_agencies.security_agency_id')
                ->whereIn('incident_agencies.incident_id', $ids)->whereNull('incident_agencies.deleted_at')
                ->orderBy('security_agencies.name')->get(['incident_agencies.incident_id', 'security_agencies.short_name', 'security_agencies.name'])
                ->groupBy('incident_id');
            $officers = OfficerNames::for($incidents->pluck('reporting_officer_id'));

            foreach ($incidents as $incident) {
                /** @var Incident $incident */
                $byOutcome = collect($casualties->get($incident->id, []))->pluck('people', 'outcome');
                $byInvolvement = collect($persons->get($incident->id, []))->pluck('people', 'involvement');
                $writer->row([
                    $incident->reference_no,
                    $incident->type->name,
                    $incident->type->category->label(),
                    $incident->severity->label(),
                    $incident->status->label(),
                    self::time($incident->occurred_at),
                    $incident->occurred_at_precision->label(),
                    $incident->state->name,
                    $incident->lga->name,
                    $incident->community?->name,
                    $incident->title,
                    ...array_map(fn (CasualtyOutcome $o) => (int) ($byOutcome[$o->value] ?? 0), CasualtyOutcome::cases()),
                    ...array_map(fn (PersonInvolvement $i) => (int) ($byInvolvement[$i->value] ?? 0), PersonInvolvement::cases()),
                    collect($agencies->get($incident->id, []))->map(fn (\stdClass $a): string => (string) ($a->short_name ?: $a->name))->unique()->implode(', '),
                    $incident->escalated_at === null ? 'No' : ($incident->escalation_acknowledged_at === null ? 'Escalated' : 'Escalated, acknowledged'),
                    $officers->get($incident->reporting_officer_id),
                    self::time($incident->submitted_at),
                    self::time($incident->returned_at),
                    self::time($incident->verified_at),
                    self::time($incident->investigation_started_at),
                    self::time($incident->resolved_at),
                    self::time($incident->closed_at),
                    self::time($incident->escalated_at),
                    self::time($incident->escalation_acknowledged_at),
                ]);
            }
        }, 'incidents.id', 'id');

        $writer->close();

        return new ReportOutput($path, $levels->level(), rows: $rows);
    }

    /**
     * The export's rows: the scoped, filtered incidents without drafts and
     * invalid reports (also used for the row estimate before generating).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Incident>
     */
    public static function query(User $user, array $filters): Builder
    {
        return IncidentFilters::from($filters)->apply(Incident::query(), $user, sort: false)
            ->whereNotIn('incidents.status', [IncidentStatus::Draft->value, IncidentStatus::Invalid->value])
            ->select('incidents.*');
    }

    /** @return list<string> */
    public static function headings(): array
    {
        return [
            'Reference', 'Type', 'Category', 'Severity', 'Status', 'Occurred at', 'Time precision', 'State', 'LGA', 'Community', 'Title',
            ...array_map(fn (CasualtyOutcome $o) => 'Casualties: '.$o->label(), CasualtyOutcome::cases()),
            ...array_map(fn (PersonInvolvement $i) => 'Persons: '.$i->label(), PersonInvolvement::cases()),
            'Agencies involved', 'Escalation', 'Reporting officer',
            'Submitted', 'Returned', 'Verified', 'Investigation started', 'Resolved', 'Closed', 'Escalated', 'Escalation acknowledged',
        ];
    }

    private static function time(?DateTimeInterface $at): ?string
    {
        return $at === null ? null : CarbonImmutable::instance($at)->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i');
    }
}
