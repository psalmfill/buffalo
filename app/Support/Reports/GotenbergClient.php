<?php

namespace App\Support\Reports;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * HTML → PDF through Gotenberg (Phase 7D), on the internal `reports`
 * network only. Every request carries its own files: the page, the header
 * and footer templates (repeated on every page by Chromium), the report CSS,
 * the Inter font and Chart.js. Templates reference them by relative name;
 * Chromium is allowed to load nothing else (file:///tmp/… only).
 *
 * Gotenberg waits until the template sets `window.reportReady = true`
 * (charts drawn with animation off).
 */
final class GotenbergClient
{
    /** Files sent with every request (resources/reports/assets). */
    public const ASSETS = ['report.css', 'inter-latin-wght-normal.woff2', 'chart.umd.min.js'];

    /**
     * @param  array<string, string>  $extra  more files: name => contents
     * @return string the PDF bytes
     */
    public function render(string $html, string $header, string $footer, array $extra = []): string
    {
        $request = Http::timeout((int) config('buffalo.reports.gotenberg_timeout'))
            ->attach('files', $html, 'index.html')
            ->attach('files', $header, 'header.html')
            ->attach('files', $footer, 'footer.html');

        foreach (self::ASSETS as $asset) {
            $request = $request->attach('files', (string) file_get_contents(resource_path('reports/assets/'.$asset)), $asset);
        }

        foreach ($extra as $name => $contents) {
            $request = $request->attach('files', $contents, $name);
        }

        $response = $request->post(rtrim((string) config('buffalo.reports.gotenberg_url'), '/').'/forms/chromium/convert/html', [
            'paperWidth' => '8.27',
            'paperHeight' => '11.69',
            'marginTop' => '0.8',
            'marginBottom' => '0.8',
            'marginLeft' => '0.5',
            'marginRight' => '0.5',
            'printBackground' => 'true',
            'waitForExpression' => 'window.reportReady === true',
            'skipNetworkIdleEvent' => 'true',
        ]);

        if (! $response->successful() || ! str_starts_with($response->body(), '%PDF')) {
            // The response may echo template content: log the status only.
            throw new RuntimeException('Gotenberg failed to render the report (HTTP '.$response->status().').');
        }

        return $response->body();
    }
}
