<?php

namespace App\Services\Reports;

use App\Enums\ReportType;
use App\Services\Reports\Builders\AnalyticsReportBuilder;
use App\Services\Reports\Builders\CoverageReportBuilder;
use App\Services\Reports\Builders\IncidentListBuilder;
use App\Services\Reports\Builders\IncidentReportBuilder;
use App\Services\Reports\Builders\LgaProfileBuilder;
use App\Services\Reports\Builders\SitrepBuilder;
use App\Services\Reports\Builders\SummaryBuilder;

/** The builder for each report type (Phase 7D). */
final class ReportBuilders
{
    public static function for(ReportType $type): ReportBuilder
    {
        return app(match ($type) {
            ReportType::Sitrep => SitrepBuilder::class,
            ReportType::Weekly, ReportType::Monthly => SummaryBuilder::class,
            ReportType::IncidentList => IncidentListBuilder::class,
            ReportType::Incident => IncidentReportBuilder::class,
            ReportType::LgaProfile => LgaProfileBuilder::class,
            ReportType::Coverage => CoverageReportBuilder::class,
            ReportType::Analytics => AnalyticsReportBuilder::class,
        });
    }
}
