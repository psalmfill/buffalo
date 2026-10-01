<?php

namespace App\Support\Dashboard;

use App\Enums\ConcernLevel;
use App\Enums\FlashpointStatus;
use App\Enums\ThreatStatus;
use App\Enums\ThreatUrgency;
use App\Models\Flashpoint;
use App\Models\RecordDissemination;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Jurisdiction\Contracts\Disseminable;
use App\Support\Jurisdiction\DisseminationTargets;
use Illuminate\Database\Eloquent\Model;

/**
 * Dissemination cards on the dashboards (Phase 7A), through the viewer's
 * scopes:
 * - state and LGA: active records shared with us and not yet acknowledged;
 * - national: urgent threat reports and flashpoints whose areas reach
 *   places they have not been disseminated to.
 */
final class DisseminationFigures
{
    private const LIMIT = 10;

    /** Candidates examined for the national card. */
    private const NATIONAL_CANDIDATES = 200;

    public function __construct(private readonly DisseminationTargets $targets) {}

    /**
     * @return array{count: int, rows: list<array<string, mixed>>}|null
     */
    public function unacknowledged(User $user): ?array
    {
        if (! $user->can('viewAny', RecordDissemination::class)) {
            return null;
        }

        $rows = RecordDissemination::query()->receivedBy($user)->active()->whereNull('acknowledged_at')
            ->with('sourceState')->orderByDesc('disseminated_at')->get();
        $visible = [];

        foreach ($rows as $row) {
            $subject = RecordDissemination::subjectClass($row->subject_type)::query()->whereKey($row->subject_id)->first();

            if ($subject !== null && ! isset($visible[$row->subject_type.$row->subject_id])) {
                $visible[$row->subject_type.$row->subject_id] = [
                    'ulid' => $row->ulid,
                    'kind' => $subject->disseminationKind(),
                    'label' => $subject->disseminationLabel(),
                    'source' => $row->sourceState->name.' State',
                    'disseminated_at' => $row->disseminated_at->toIso8601String(),
                    'href' => $subject->disseminationPath(),
                ];
            }
        }

        return ['count' => count($visible), 'rows' => array_slice(array_values($visible), 0, self::LIMIT)];
    }

    /**
     * @return array{count: int, rows: list<array<string, mixed>>}|null
     */
    public function crossBorderGaps(User $user): ?array
    {
        if (! $user->can('viewAny', ThreatReport::class)) {
            return null;
        }

        $threats = ThreatReport::query()->ownJurisdiction($user)->with('state')
            ->where('threat_reports.status', ThreatStatus::Active->value)
            ->whereIn('threat_reports.urgency', [ThreatUrgency::Immediate->value, ThreatUrgency::Priority->value])
            ->whereNotNull('threat_reports.location')->whereNotNull('threat_reports.radius_meters')
            ->byUrgency()->limit(self::NATIONAL_CANDIDATES)->get();
        $flashpoints = $user->can('viewAny', Flashpoint::class)
            ? Flashpoint::query()->ownJurisdiction($user)->with('state')
                ->whereIn('flashpoints.status', [FlashpointStatus::Active->value, FlashpointStatus::Monitoring->value])
                ->whereIn('flashpoints.concern_level', [ConcernLevel::High->value, ConcernLevel::Severe->value])
                ->byConcern()->limit(self::NATIONAL_CANDIDATES)->get()
            : collect();

        $rows = [];

        foreach ([...$threats->all(), ...$flashpoints->all()] as $subject) {
            $places = $this->targets->present($this->targets->uncovered($subject));

            if ($places !== []) {
                $rows[] = $this->gapRow($subject, $places);
            }
        }

        return ['count' => count($rows), 'rows' => array_slice($rows, 0, self::LIMIT)];
    }

    /**
     * @param  list<array{level: string, ulid: string, name: string, state: string}>  $places
     * @return array<string, mixed>
     */
    private function gapRow(Model&Disseminable $subject, array $places): array
    {
        return [
            'kind' => $subject->disseminationKind(),
            'label' => $subject->disseminationLabel(),
            'state' => $subject->getRelationValue('state')?->getAttribute('name'),
            'places' => array_column($places, 'name'),
            'href' => $subject->disseminationPath(),
        ];
    }
}
