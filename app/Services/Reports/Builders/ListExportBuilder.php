<?php

namespace App\Services\Reports\Builders;

use App\Enums\Classification;
use App\Enums\ReportFormat;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportMarkings;
use App\Services\Reports\ReportOutput;
use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Exports\Lists\ListExporters;
use App\Support\Reports\SpreadsheetWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Any registered list as XLSX, CSV or a PDF table (Phase 11E). The rows are
 * the exporter's query, i.e. the page's scoped query for the stored
 * filters, re-run as the requester at generation time; the columns are the
 * ones chosen at request (validated against the exporter's allowed set).
 * The file's level is the highest classification among its rows.
 */
final class ListExportBuilder implements ReportBuilder
{
    private const CHUNK = 1000;

    public function __construct(private readonly PdfRenderer $pdf) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $exporter = ListExporters::find((string) ($run->parameters['list'] ?? ''));

        if ($exporter === null || ! $user->can('exportList', [ReportRun::class, $exporter])) {
            throw new AuthorizationException('The requester may no longer export this list.');
        }

        $filters = $exporter->filters((array) ($run->parameters['filters'] ?? []), $user);
        /** @var list<string> $keys */
        $keys = array_values(array_filter((array) ($run->parameters['columns'] ?? []), 'is_string'));
        $columns = $exporter->selected($keys);
        $rows = $exporter->count($user, $filters);
        $max = self::limit($exporter, $run->format);

        if ($rows > $max) {
            throw new RuntimeException("List export of {$rows} rows exceeds the limit of {$max}.");
        }

        $levels = new LevelTracker;
        /** @var iterable<Classification|string|null> $found */
        $found = $exporter->around(fn () => $exporter->levels($user, $filters));
        $levels->seeAll($found);

        $title = $exporter->label();
        $headings = array_map(fn (ListColumn $c) => $c->label, $columns);

        if ($run->format === ReportFormat::Pdf) {
            $data = [];
            $this->each($exporter, $user, $filters, function (Model $row) use (&$data, $columns): void {
                $data[] = array_map(fn (ListColumn $c) => $c->read($row), $columns);
            });

            return $this->pdf->render('reports.list', [
                'headings' => $headings,
                'rows' => $data,
                'subtitle' => self::filterSummary($filters),
            ], $title, $run, $user, $levels);
        }

        $markings = ReportMarkings::for($run, $user, $levels->level());
        $path = tempnam(sys_get_temp_dir(), 'buffalo-report-').'.'.$run->format->value;
        $writer = new SpreadsheetWriter($run->format->value, $path, $markings->rows($title), $title);
        $writer->header($headings);
        $this->each($exporter, $user, $filters, fn (Model $row) => $writer->row(array_map(fn (ListColumn $c) => $c->read($row), $columns)));
        $writer->close();

        return new ReportOutput($path, $levels->level(), rows: $writer->rows());
    }

    /** The most rows an export of this list may hold in this format. */
    public static function limit(ListExporter $exporter, ReportFormat $format): int
    {
        return $format === ReportFormat::Pdf
            ? min($exporter->maxRows(), (int) config('buffalo.exports.pdf_max_rows'))
            : $exporter->maxRows();
    }

    /**
     * Every row in the page's order, a chunk at a time (each chunk prepared
     * by the exporter for its batch lookups).
     *
     * @param  array<string, mixed>  $filters
     * @param  callable(Model): void  $write
     */
    private function each(ListExporter $exporter, User $user, array $filters, callable $write): void
    {
        $exporter->around(fn () => $exporter->query($user, $filters)->with($exporter->with())->lazy(self::CHUNK)
            ->chunk(self::CHUNK)
            ->each(function ($chunk) use ($exporter, $user, $write): void {
                /** @var Collection<int, Model> $rows */
                $rows = new Collection($chunk->values()->all());
                $exporter->prepare($rows, $user);
                $rows->each(fn (Model $row) => $write($row));
            }));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private static function filterSummary(array $filters): string
    {
        return $filters === [] ? 'No filters: every row you can see.' : count($filters).' filter(s) applied, as on the page.';
    }
}
