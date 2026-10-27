<?php

namespace App\Enums;

/**
 * Report types (Phase 7D, extended in 11E and 11F). PDF reports need
 * reports.generate; list exports (`list`, any registered ListExporter)
 * need exports.lists, or exports.sensitive for the sensitive lists. Each
 * also needs its module's view permission (ReportPolicy).
 *
 * `incident_list` is kept for runs requested before 11E only: the incident
 * list is now exported through the registry like every other list.
 */
enum ReportType: string
{
    case Sitrep = 'sitrep';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case IncidentList = 'incident_list';
    case Incident = 'incident';
    case LgaProfile = 'lga_profile';
    case StateProfile = 'state_profile';
    case Coverage = 'coverage';
    case Analytics = 'analytics';
    case ListExport = 'list';
    case Flashpoint = 'flashpoint';
    case OrganizationSite = 'organization_site';
    case Arrest = 'arrest';
    case ThreatReport = 'threat_report';
    case CriminalGroup = 'criminal_group';
    case Person = 'person';

    public function label(): string
    {
        return match ($this) {
            self::Sitrep => 'Daily situation report',
            self::Weekly => 'Weekly summary',
            self::Monthly => 'Monthly summary',
            self::IncidentList => 'Incident list export',
            self::Incident => 'Incident report',
            self::LgaProfile => 'LGA profile',
            self::StateProfile => 'State profile',
            self::Coverage => 'Coverage report',
            self::Analytics => 'Analytics report',
            self::ListExport => 'List export',
            self::Flashpoint => 'Flashpoint',
            self::OrganizationSite => 'Organization site',
            self::Arrest => 'Arrest',
            self::ThreatReport => 'Threat report',
            self::CriminalGroup => 'Criminal group',
            self::Person => 'Person record',
        };
    }

    /**
     * @return list<ReportFormat>
     */
    public function formats(): array
    {
        return match ($this) {
            self::IncidentList => [ReportFormat::Xlsx, ReportFormat::Csv],
            self::ListExport => [ReportFormat::Xlsx, ReportFormat::Csv, ReportFormat::Pdf],
            default => [ReportFormat::Pdf],
        };
    }

    public function schedulable(): bool
    {
        return in_array($this, [self::Sitrep, self::Weekly, self::Monthly], true);
    }

    /** One record, printed from its page (Phase 7D incident and coverage; 11E the rest). */
    public function isRecord(): bool
    {
        return in_array($this, [self::Incident, self::Coverage, self::Flashpoint, self::OrganizationSite, self::Arrest, self::ThreatReport, self::CriminalGroup, self::Person], true);
    }

    /** Requested from the /reports page (record PDFs and list exports come from their pages). */
    public function offeredOnReportsPage(): bool
    {
        return in_array($this, [self::Sitrep, self::Weekly, self::Monthly, self::Incident, self::LgaProfile, self::StateProfile, self::Coverage, self::Analytics], true);
    }

    /**
     * The ULID parameter of a single-record type.
     */
    public function recordParameter(): ?string
    {
        return match ($this) {
            self::Incident => 'incident',
            self::Coverage => 'report',
            self::Flashpoint => 'flashpoint',
            self::OrganizationSite => 'site',
            self::Arrest => 'arrest',
            self::ThreatReport => 'threat',
            self::CriminalGroup => 'group',
            self::Person => 'person',
            default => null,
        };
    }
}
