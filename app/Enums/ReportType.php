<?php

namespace App\Enums;

/**
 * Report types (Phase 7D). PDF reports need reports.generate, the incident
 * list export reports.export_data; each also needs its module's view
 * permission (ReportPolicy).
 */
enum ReportType: string
{
    case Sitrep = 'sitrep';
    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case IncidentList = 'incident_list';
    case Incident = 'incident';
    case LgaProfile = 'lga_profile';
    case Coverage = 'coverage';
    case Analytics = 'analytics';

    public function label(): string
    {
        return match ($this) {
            self::Sitrep => 'Daily situation report',
            self::Weekly => 'Weekly summary',
            self::Monthly => 'Monthly summary',
            self::IncidentList => 'Incident list export',
            self::Incident => 'Incident report',
            self::LgaProfile => 'LGA profile',
            self::Coverage => 'Coverage report',
            self::Analytics => 'Analytics report',
        };
    }

    /**
     * @return list<ReportFormat>
     */
    public function formats(): array
    {
        return $this === self::IncidentList ? [ReportFormat::Xlsx, ReportFormat::Csv] : [ReportFormat::Pdf];
    }

    public function schedulable(): bool
    {
        return in_array($this, [self::Sitrep, self::Weekly, self::Monthly], true);
    }

    /** The permission besides the module's own (reports.generate or reports.export_data). */
    public function permission(): Permission
    {
        return $this === self::IncidentList ? Permission::ReportsExportData : Permission::ReportsGenerate;
    }
}
