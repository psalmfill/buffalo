<?php

namespace App\Services\Reports\Builders;

use App\Enums\AccessAction;
use App\Http\Presenters\EvidencePresenter;
use App\Http\Presenters\IncidentPresenter;
use App\Http\Presenters\IncidentTimeline;
use App\Models\Incident;
use App\Models\IncidentAgency;
use App\Models\IncidentCasualty;
use App\Models\IncidentCriminalGroup;
use App\Models\IncidentEvidence;
use App\Models\IncidentPerson;
use App\Models\IncidentProperty;
use App\Models\IncidentUpdate;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * One incident as a PDF (Phase 7D): the 5D print layout (overview, people,
 * response, evidence titles, timeline) rendered through Gotenberg. Every
 * related row is read through its own scopes, so person names appear only
 * as the requester could see them on screen and nothing above their
 * clearance is included. The level is the highest among the incident and
 * the evidence and follow-ups shown. Generation is access-logged (`print`).
 */
final class IncidentReportBuilder implements ReportBuilder
{
    public function __construct(private readonly PdfRenderer $pdf) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $incident = Incident::query()->withCoordinates()->where('ulid', (string) $run->parameters['incident'])->first();

        if ($incident === null || ! $user->can('view', $incident)) {
            throw new AuthorizationException('The incident is no longer visible to the requester.');
        }

        $incident->load(['type', 'lga', 'state', 'community']);
        $incident->recordAccess(AccessAction::Print, 'report');
        $levels = new LevelTracker;
        $levels->see($incident->classificationLevel());
        $evidence = IncidentEvidence::query()->withCoordinates()->with('media')->where('incident_id', $incident->id)->orderBy('id')->get();
        $levels->seeAll($evidence->map->classificationLevel());
        $levels->seeAll(IncidentUpdate::query()->where('incident_id', $incident->id)->toBase()->distinct()->pluck('classification'));
        $detail = IncidentPresenter::detail($incident);

        return $this->pdf->render('reports.incident', [
            'incident' => $detail,
            'details' => self::details($detail['type']['fields'] ?? [], (array) $detail['details']),
            'casualties' => IncidentCasualty::query()->with('agency')->where('incident_id', $incident->id)->orderBy('id')->get()
                ->map(fn (IncidentCasualty $c) => IncidentPresenter::casualty($c))->all(),
            'persons' => IncidentPerson::query()->with('person.personOfInterest')->where('incident_id', $incident->id)->orderBy('id')->get()
                ->map(fn (IncidentPerson $p) => IncidentPresenter::person($p, $user))->all(),
            'groups' => IncidentCriminalGroup::query()->with('group')->where('incident_id', $incident->id)->orderBy('id')->get()
                ->map(fn (IncidentCriminalGroup $g) => IncidentPresenter::group($g))->all(),
            'agencies' => IncidentAgency::query()->with(['agency', 'formation'])->where('incident_id', $incident->id)->orderBy('id')->get()
                ->map(fn (IncidentAgency $a) => IncidentPresenter::agency($a))->all(),
            'properties' => IncidentProperty::query()->with(['propertyType', 'site'])->where('incident_id', $incident->id)->orderBy('id')->get()
                ->map(fn (IncidentProperty $p) => IncidentPresenter::property($p))->all(),
            'evidence' => array_map(fn (array $item) => ['title' => $item['title'], 'type' => $item['type']['label'], 'classification' => $item['classification']['label']], EvidencePresenter::list($evidence, $user)),
            'timeline' => IncidentTimeline::for($incident),
        ], 'Incident report — '.($incident->reference_no ?? 'draft'), $run, $user, $levels);
    }

    /** ISO date-time to local display. */
    public static function at(?string $iso): string
    {
        return $iso === null ? '' : CarbonImmutable::parse($iso)->setTimezone((string) config('app.timezone'))->format('j M Y H:i');
    }

    /**
     * Saved detail values as label => display text, like DynamicFieldsView.
     *
     * @param  array<int, mixed>  $fields
     * @param  array<string, mixed>  $values
     * @return array<string, string>
     */
    private static function details(array $fields, array $values): array
    {
        $shown = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ! isset($field['key'], $field['label'])) {
                continue;
            }

            $value = $values[$field['key']] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $options = collect((array) ($field['options'] ?? []))->pluck('label', 'value');
            $label = fn (mixed $raw) => (string) ($options[(string) $raw] ?? $raw);
            $shown[$field['label'].(($field['deprecated'] ?? false) ? ' (no longer used)' : '')] = match ($field['type'] ?? 'text') {
                'boolean' => $value ? 'Yes' : 'No',
                'select' => $label($value),
                'multiselect' => implode(', ', array_map($label, (array) $value)),
                'integer', 'number' => is_numeric($value) ? number_format((float) $value, is_float($value) ? 2 : 0) : (string) $value,
                'datetime' => self::at((string) $value),
                default => is_scalar($value) ? (string) $value : (string) json_encode($value),
            };
        }

        return $shown;
    }
}
