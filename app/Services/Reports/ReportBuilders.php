<?php

namespace App\Services\Reports;

use App\Enums\ReportType;
use App\Services\Reports\Builders\AnalyticsReportBuilder;
use App\Services\Reports\Builders\ArrestReportBuilder;
use App\Services\Reports\Builders\CoverageReportBuilder;
use App\Services\Reports\Builders\CriminalGroupReportBuilder;
use App\Services\Reports\Builders\FlashpointReportBuilder;
use App\Services\Reports\Builders\IncidentListBuilder;
use App\Services\Reports\Builders\IncidentReportBuilder;
use App\Services\Reports\Builders\LgaProfileBuilder;
use App\Services\Reports\Builders\ListExportBuilder;
use App\Services\Reports\Builders\OrganizationSiteReportBuilder;
use App\Services\Reports\Builders\PersonReportBuilder;
use App\Services\Reports\Builders\SitrepBuilder;
use App\Services\Reports\Builders\SummaryBuilder;
use App\Services\Reports\Builders\ThreatReportBuilder;

/** The builder for each report type (Phase 7D; list exports and record PDFs 11E). */
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
            ReportType::ListExport => ListExportBuilder::class,
            ReportType::Flashpoint => FlashpointReportBuilder::class,
            ReportType::OrganizationSite => OrganizationSiteReportBuilder::class,
            ReportType::Arrest => ArrestReportBuilder::class,
            ReportType::ThreatReport => ThreatReportBuilder::class,
            ReportType::CriminalGroup => CriminalGroupReportBuilder::class,
            ReportType::Person => PersonReportBuilder::class,
        });
    }
}
