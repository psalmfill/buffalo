<?php

namespace Tests\Support\Exports;

use App\Enums\ReportFormat;
use App\Models\ReportRun;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/**
 * Reading generated list exports in tests (Phase 11E).
 */
final class ExportFiles
{
    /** @return list<list<string>> CSV or XLSX rows, as text (markings first, blank rows dropped) */
    public static function rows(ReportRun $run): array
    {
        $path = Storage::disk('reports')->path((string) $run->file_path);

        if ($run->format === ReportFormat::Csv) {
            $lines = array_filter(explode("\n", ltrim((string) file_get_contents($path), "\u{FEFF}")), fn (string $l) => trim($l, ", \r") !== '');

            return array_values(array_map(fn (string $line) => array_map('strval', str_getcsv($line, escape: '')), $lines));
        }

        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $values = array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('Y-m-d H:i') : (string) $v, $row->toArray());
                if (array_filter($values, fn ($v) => $v !== '') !== []) {
                    $rows[] = $values;
                }
            }
        }
        $reader->close();

        return $rows;
    }

    /**
     * The data rows (after the three marking rows and the header), keyed by heading.
     *
     * @return list<array<string, string>>
     */
    public static function data(ReportRun $run): array
    {
        $rows = self::rows($run);
        $header = $rows[3];

        return array_values(array_map(fn (array $row) => array_combine($header, array_pad(array_slice($row, 0, count($header)), count($header), '')), array_slice($rows, 4)));
    }

    public static function bytes(ReportRun $run): string
    {
        return (string) file_get_contents(Storage::disk('reports')->path((string) $run->file_path));
    }

    /** The text of a PDF run (pdftotext). */
    public static function pdfText(ReportRun $run): string
    {
        return (string) shell_exec('pdftotext -layout '.escapeshellarg(Storage::disk('reports')->path((string) $run->file_path)).' - 2>/dev/null');
    }
}
