<?php

namespace App\Http\Controllers\Analytics;

use App\Enums\AccessAction;
use App\Enums\IncidentCategory;
use App\Enums\JurisdictionLevel;
use App\Enums\Severity;
use App\Http\Controllers\Controller;
use App\Models\AnalyticsExport;
use App\Models\GeopoliticalZone;
use App\Models\IncidentType;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\AnalyticsArea;
use App\Services\Analytics\AnalyticsFilters;
use App\Services\Analytics\ExportMarking;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Analytics (Phase 7C): one tab computed per request, all filters in the
 * URL (shareable; each viewer sees only their own scopes). Chart exports:
 * CSV rebuilt here through the viewer's scopes, PNG drawn in the browser;
 * both logged in record_access_logs (action export, context analytics).
 */
class AnalyticsController extends Controller
{
    public function index(Request $request, Analytics $analytics, JurisdictionOptions $options): Response
    {
        $this->authorize('viewAnalytics');
        $user = $this->actor($request);
        $areas = $analytics->areas($user);
        $tab = $this->area($analytics, $user, (string) $request->query('tab', '')) ?? ($areas[0] ?? null);
        $filters = AnalyticsFilters::from($request->query(), $user);

        return Inertia::render('analytics/index', [
            'tabs' => array_map(fn (AnalyticsArea $a) => ['key' => $a->key(), 'label' => $a->label()], $areas),
            'tab' => $tab?->key(),
            'charts' => $tab === null ? [] : $analytics->charts($user, $tab, $filters),
            'filters' => $filters->describe(),
            'incidentQuery' => $filters->incidentListQuery(),
            'options' => $this->options($user, $options, $filters),
            'marking' => [
                'classification' => ExportMarking::classification($user),
                'user' => $user->name,
            ],
            'can' => ['export' => $user->can('exportAnalytics')],
        ]);
    }

    public function csv(Request $request, string $area, string $chart, Analytics $analytics): StreamedResponse
    {
        $this->authorize('exportAnalytics');
        $user = $this->actor($request);
        $tab = $this->area($analytics, $user, $area);
        abort_if($tab === null, 404);
        $filters = AnalyticsFilters::from($request->query(), $user);
        $data = collect($analytics->charts($user, $tab, $filters))->firstWhere('key', $chart);
        abort_if($data === null, 404);

        $this->logExport($user, $area, $chart, 'csv', $filters);
        $lines = [
            [ExportMarking::classification($user)],
            [ExportMarking::generated($user)],
            [$data['title'].' — '.$filters->periodLabel().($filters->hasComparison() ? ' compared with '.$filters->periodLabel(comparison: true) : '')],
            [],
            array_map(fn (array $c) => $c['label'], $data['columns']),
            ...array_map(fn (array $row) => array_map(fn (array $c) => $row[$c['key']] ?? '', $data['columns']), $data['rows']),
            [],
            [ExportMarking::classification($user)],
        ];

        return response()->streamDownload(function () use ($lines): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            foreach ($lines as $line) {
                fputcsv($out, array_map(self::safe(...), $line), escape: '');
            }
            fclose($out);
        }, "buffalo-analytics-{$area}-{$chart}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Logs a PNG export (drawn in the browser) and returns the markings to
     * draw into the image.
     */
    public function png(Request $request, Analytics $analytics): JsonResponse
    {
        $this->authorize('exportAnalytics');
        $user = $this->actor($request);
        $data = $request->validate([
            'area' => ['required', 'string', 'max:32'],
            'chart' => ['required', 'string', 'max:64'],
            'filters' => ['nullable', 'array'],
        ]);
        abort_if($this->area($analytics, $user, $data['area']) === null, 404);
        $filters = AnalyticsFilters::from((array) ($data['filters'] ?? []), $user);

        $this->logExport($user, $data['area'], $data['chart'], 'png', $filters);

        return response()->json([
            'classification' => ExportMarking::classification($user),
            'generated' => ExportMarking::generated($user),
            'period' => $filters->periodLabel(),
        ]);
    }

    private function logExport(User $user, string $area, string $chart, string $format, AnalyticsFilters $filters): void
    {
        $export = new AnalyticsExport;
        $export->forceFill(['user_id' => $user->id, 'area' => $area, 'chart' => $chart, 'format' => $format, 'filters' => $filters->values()])->save();
        $export->recordAccess(AccessAction::Export, 'analytics');
    }

    /** Neutralises spreadsheet formulas in text cells (CLAUDE.md decisions log). */
    private static function safe(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        $text = (string) $value;

        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$text : $text;
    }

    private function area(Analytics $analytics, User $user, string $key): ?AnalyticsArea
    {
        return $key === '' ? null : $analytics->area($user, $key);
    }

    /**
     * @return array<string, mixed>
     */
    private function options(User $user, JurisdictionOptions $options, AnalyticsFilters $filters): array
    {
        $states = $options->states($user);
        $state = $filters->state;

        return [
            'presets' => [
                ['value' => '7d', 'label' => 'Last 7 days'], ['value' => '30d', 'label' => 'Last 30 days'], ['value' => '90d', 'label' => 'Last 90 days'],
                ['value' => 'this_month', 'label' => 'This month'], ['value' => 'last_month', 'label' => 'Last month'],
                ['value' => 'this_quarter', 'label' => 'This quarter'], ['value' => 'last_quarter', 'label' => 'Last quarter'],
                ['value' => 'this_year', 'label' => 'This year'], ['value' => 'custom', 'label' => 'Custom'],
            ],
            'comparisons' => [['value' => 'none', 'label' => 'No comparison'], ['value' => 'previous', 'label' => 'Previous period'], ['value' => 'last_year', 'label' => 'Same period last year']],
            'granularities' => [['value' => 'day', 'label' => 'Day'], ['value' => 'week', 'label' => 'Week'], ['value' => 'month', 'label' => 'Month'], ['value' => 'quarter', 'label' => 'Quarter']],
            'zones' => $user->jurisdiction_level === JurisdictionLevel::National
                ? GeopoliticalZone::query()->whereIn('id', $states->pluck('geopolitical_zone_id'))->orderBy('name')->get(['ulid', 'name'])->map(fn (GeopoliticalZone $z) => ['value' => $z->ulid, 'label' => $z->name])->values()
                : [],
            'states' => $states->count() > 1 ? $states->sortBy('name')->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values() : [],
            'lgas' => $state !== null || $states->count() === 1
                ? $options->lgaQuery($user)->active()->where('lgas.state_id', $state->id ?? $states->first()?->id)->orderBy('name')->get(['ulid', 'name'])->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name])->values()
                : [],
            'types' => IncidentType::query()->orderBy('name')->get(['ulid', 'name'])->map(fn (IncidentType $t) => ['value' => $t->ulid, 'label' => $t->name])->values(),
            'categories' => array_map(fn (IncidentCategory $c) => ['value' => $c->value, 'label' => $c->label()], IncidentCategory::cases()),
            'severities' => array_map(fn (Severity $s) => ['value' => $s->value, 'label' => $s->label()], Severity::cases()),
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
