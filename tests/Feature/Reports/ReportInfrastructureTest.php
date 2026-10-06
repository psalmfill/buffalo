<?php

use App\Support\Reports\GotenbergClient;
use App\Support\Reports\SafeCell;
use App\Support\Reports\SpreadsheetWriter;
use Illuminate\Support\Facades\Http;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/*
| Phase 7D.1: Gotenberg is internal-only and cannot fetch anything but the
| files sent with a request; spreadsheet cells are neutralised against
| formula injection.
*/

/** The gotenberg service block of compose.yml. */
function gotenbergService(): string
{
    $compose = (string) file_get_contents(base_path('compose.yml'));
    preg_match('/\n  gotenberg:\n(.*?)(?=\n\S|\n  [a-z_]+:\n)/s', $compose, $m);

    return $m[1] ?? '';
}

test('the gotenberg service is pinned, internal-only, publishes no port and denies outbound URLs', function () {
    $compose = (string) file_get_contents(base_path('compose.yml'));
    $service = gotenbergService();

    expect($service)->toContain('image: gotenberg/gotenberg:8.37.0-chromium')
        ->not->toContain('ports:')
        ->not->toContain(':latest')
        ->toContain('networks: [internal-tools]')
        ->toContain('--chromium-allow-list=^file:///tmp/.*')
        ->toContain('--chromium-deny-list=^(?!file:///tmp/).*')
        ->toContain('--api-disable-download-from=true')
        ->toContain('--webhook-disable=true')
        ->toContain('--pdfengines-disable-routes=true')
        ->toContain('--prometheus-disable-collect=true');
    expect($compose)->toMatch('/\nnetworks:\n(?:.*\n)*?  internal-tools:\n    internal: true/')
        ->and((string) file_get_contents(base_path('compose.override.yml')))->not->toMatch('/gotenberg:[\s\S]*?ports:/');
});

test('gotenberg renders without fetching external URLs, and unused routes are disabled', function () {
    $base = rtrim((string) config('buffalo.reports.gotenberg_url'), '/');
    $html = '<html><body><h1>Isolation check</h1><img src="https://example.com/x.png"><img src="http://203.0.113.5/y.png"><script>window.reportReady = true</script></body></html>';

    $pdf = app(GotenbergClient::class)->render($html, '<html><body></body></html>', '<html><body></body></html>');
    expect($pdf)->toStartWith('%PDF');

    // Converting a remote URL is refused (allow list), and the PDF engine routes are off.
    expect(Http::asMultipart()->post("{$base}/forms/chromium/convert/url", [['name' => 'url', 'contents' => 'https://example.com']])->status())->toBe(403)
        ->and(Http::post("{$base}/forms/pdfengines/merge")->status())->toBe(404)
        ->and(Http::post("{$base}/forms/libreoffice/convert")->status())->toBe(404);
});

test('cells starting with =, +, -, @, tab or CR are neutralised; numbers stay numbers', function () {
    expect(SafeCell::value('=SUM(A1)'))->toBe("'=SUM(A1)")
        ->and(SafeCell::value('+1'))->toBe("'+1")
        ->and(SafeCell::value('-2'))->toBe("'-2")
        ->and(SafeCell::value('@cmd'))->toBe("'@cmd")
        ->and(SafeCell::value("\tx"))->toBe("'\tx")
        ->and(SafeCell::value("\rx"))->toBe("'\rx")
        ->and(SafeCell::value('Normal'))->toBe('Normal')
        ->and(SafeCell::value(-5))->toBe(-5)
        ->and(SafeCell::value(null))->toBeNull();

    foreach (['xlsx', 'csv'] as $format) {
        $path = tempnam(sys_get_temp_dir(), 'rpt').'.'.$format;
        $writer = new SpreadsheetWriter($format, $path, ['RESTRICTED', 'Generated for Tester'], 'Test');
        $writer->header(['Title', 'Count']);
        $writer->row(['=HYPERLINK("http://x")', -3]);
        $writer->row(['@SUM(1)', 4]);
        $writer->close();

        if ($format === 'csv') {
            $text = (string) file_get_contents($path);
            expect($text)->toContain('RESTRICTED')->toContain("'=HYPERLINK")->toContain("'@SUM(1)")->toContain(',-3');
        } else {
            $reader = new XlsxReader;
            $reader->open($path);
            $values = [];
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    // The reader pads short rows and skips the blank spacer row.
                    $values[] = array_values(array_filter($row->toArray(), fn ($v) => $v !== ''));
                }
            }
            $reader->close();
            expect($values[0])->toBe(['RESTRICTED'])
                ->and($values[3])->toBe(['\'=HYPERLINK("http://x")', -3])
                ->and($values[4])->toBe(["'@SUM(1)", 4]);
            $zip = new ZipArchive;
            $zip->open($path);
            expect((string) $zip->getFromName('docProps/core.xml'))->toContain('RESTRICTED')->toContain('Generated for Tester');
            $zip->close();
        }
        @unlink($path);
    }
});
