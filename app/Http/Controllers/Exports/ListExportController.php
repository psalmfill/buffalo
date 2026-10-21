<?php

namespace App\Http\Controllers\Exports;

use App\Actions\Reports\RequestReport;
use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Http\Controllers\Controller;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Reports\Builders\ListExportBuilder;
use App\Support\Auth\StepUp;
use App\Support\Exports\ExportControls;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Exports\Lists\ListExporters;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * List exports (Phase 11E): the Export button on every list page. The
 * page's current filters are sent as they are; the exporter validates them
 * with the page's own rules and counts the rows through the requester's
 * scopes. Files are built by the report pipeline (`report_runs`) and
 * downloaded from "My reports".
 */
class ListExportController extends Controller
{
    /** What the export dialog offers: columns, formats, the row count and the controls that apply. */
    public function preview(Request $request, string $list): JsonResponse
    {
        $exporter = $this->exporter($list);
        $this->authorize('exportList', [ReportRun::class, $exporter]);
        $user = $this->actor($request);
        $filters = $exporter->filters((array) $request->input('filters', []), $user);
        $rows = $exporter->count($user, $filters);

        return response()->json([
            'list' => $exporter->key(),
            'label' => $exporter->label(),
            'rows' => $rows,
            'sensitive' => $exporter->sensitive(),
            'columns' => array_map(fn (ListColumn $c) => ['key' => $c->key, 'label' => $c->label], $exporter->columns()),
            'defaults' => $exporter->defaultColumns(),
            'formats' => array_map(fn (ReportFormat $f) => [
                'value' => $f->value,
                'limit' => ListExportBuilder::limit($exporter, $f),
                'over_limit' => $rows > ListExportBuilder::limit($exporter, $f),
            ], ReportType::ListExport->formats()),
            'step_up' => RequestReport::needsStepUpFor($exporter, $rows),
            'reason_min' => $exporter->sensitive() ? (int) config('buffalo.exports.sensitive_reason_min') : null,
            'sensitive_left' => $exporter->sensitive()
                ? max(0, (int) config('buffalo.exports.daily_sensitive_exports') - app(ExportControls::class)->usage($user)['sensitive_exports'])
                : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, RequestReport $requestReport): RedirectResponse
    {
        $data = $request->validate([
            'list' => ['required', 'string', 'max:64'],
            'format' => ['required', Rule::enum(ReportFormat::class)],
            'filters' => ['nullable', 'array'],
            'columns' => ['nullable', 'array', 'max:100'],
            'columns.*' => ['string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);
        $exporter = $this->exporter((string) $data['list']);
        $this->authorize('exportList', [ReportRun::class, $exporter]);

        $run = $requestReport($this->actor($request), ReportType::ListExport, ReportFormat::from((string) $data['format']), [
            'list' => $exporter->key(),
            'filters' => (array) ($data['filters'] ?? []),
            'columns' => (array) ($data['columns'] ?? []),
            'reason' => $data['reason'] ?? null,
        ], stepUpConfirmed: StepUp::isRecent($request));

        return redirect()->route('reports.index', ['run' => $run->ulid])
            ->with('toast', ['type' => 'success', 'message' => "{$exporter->label()} export requested. It will appear in My reports when ready."]);
    }

    private function exporter(string $key): ListExporter
    {
        $exporter = ListExporters::find($key);
        abort_if($exporter === null, 404);

        return $exporter;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
