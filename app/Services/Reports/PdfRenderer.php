<?php

namespace App\Services\Reports;

use App\Models\ReportRun;
use App\Models\User;
use App\Support\Reports\GotenbergClient;

/**
 * Renders a report Blade view to PDF (Phase 7D) with the markings on every
 * page: Chromium repeats the header (classification, "Generated for …",
 * reference) and the footer (classification, page X of Y) on each page,
 * and the layout's fixed, faint diagonal watermark carries the requester's
 * name. The level is the highest classification among the records the
 * builder included.
 */
final class PdfRenderer
{
    public function __construct(private readonly GotenbergClient $gotenberg) {}

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data, string $title, ReportRun $run, User $user, LevelTracker $levels): ReportOutput
    {
        $markings = ReportMarkings::for($run, $user, $levels->level());
        $shared = ['markings' => $markings, 'title' => $title, 'run' => $run];

        $pdf = $this->gotenberg->render(
            view($view, [...$data, ...$shared])->render(),
            view('reports.header', $shared)->render(),
            view('reports.footer', $shared)->render(),
        );

        $path = tempnam(sys_get_temp_dir(), 'buffalo-report-');
        file_put_contents($path, $pdf);

        return new ReportOutput($path, $levels->level(), pages: self::pages($pdf));
    }

    /** Page objects in the PDF (Chromium writes one /Type /Page per page). */
    public static function pages(string $pdf): int
    {
        return (int) preg_match_all('#/Type\s*/Page(?![s\w])#', $pdf);
    }
}
