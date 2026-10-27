<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Exports\Lists\ListExporters;

/**
 * Reports (Phase 7D) and list exports (Phase 11E).
 *
 * | Ability    | Rule                                                                  |
 * |------------|-----------------------------------------------------------------------|
 * | generate   | PDF reports: reports.generate AND the module's view permission;       |
 * |            | monthly summaries: DG, DDG, SD, DSD                                   |
 * | exportList | exports.lists (exports.sensitive for the sensitive lists) AND the     |
 * |            | list's own view permission; super admins: the users list only         |
 * | download   | the requester only (nobody else, not even the DG), while they may     |
 * |            | still generate that report or export that list                        |
 * | schedule   | reports.schedule AND generate, for SITREP, weekly and monthly only     |
 *
 * Single-record reports also need `view` on that record, checked by
 * ReportParameters (and again by the builder at generation).
 */
final class ReportPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::ReportsGenerate->value)
            || $actor->checkPermissionTo(Permission::ExportsLists->value)
            || $actor->checkPermissionTo(Permission::ExportsSensitive->value);
    }

    public function generate(User $actor, ReportType $type): bool
    {
        if ($type === ReportType::ListExport) {
            return $actor->checkPermissionTo(Permission::ExportsLists->value) || $actor->checkPermissionTo(Permission::ExportsSensitive->value);
        }

        // Superseded by the incidents list exporter (Phase 11E).
        if ($type === ReportType::IncidentList || ! $actor->checkPermissionTo(Permission::ReportsGenerate->value)) {
            return false;
        }

        return match ($type) {
            ReportType::Sitrep, ReportType::Weekly, ReportType::Incident => $actor->checkPermissionTo(Permission::IncidentsView->value),
            ReportType::Monthly => $actor->checkPermissionTo(Permission::IncidentsView->value)
                && in_array($actor->getRole(), [Role::DirectorGeneral, Role::DeputyDirectorGeneral, Role::StateDirector, Role::DeputyStateDirector], true),
            ReportType::LgaProfile => $actor->checkPermissionTo(Permission::LgasView->value),
            ReportType::StateProfile => $actor->checkPermissionTo(Permission::StatesView->value),
            ReportType::Coverage => $actor->checkPermissionTo(Permission::CoverageView->value),
            ReportType::Analytics => $actor->checkPermissionTo(Permission::AnalyticsView->value),
            ReportType::Flashpoint => $actor->checkPermissionTo(Permission::FlashpointsView->value),
            ReportType::OrganizationSite => $actor->checkPermissionTo(Permission::OrganizationsView->value),
            ReportType::Arrest => $actor->checkPermissionTo(Permission::ArrestsView->value),
            ReportType::ThreatReport => $actor->checkPermissionTo(Permission::ThreatsView->value),
            ReportType::CriminalGroup => $actor->checkPermissionTo(Permission::CriminalGroupsView->value),
            ReportType::Person => $actor->checkPermissionTo(Permission::PersonsView->value),
        };
    }

    /** Exporting one list (Phase 11E). */
    public function exportList(User $actor, ListExporter $exporter): bool
    {
        if ($actor->getRole() === Role::SuperAdmin && ! $exporter->forSuperAdmin()) {
            return false;
        }

        if ($exporter->forSuperAdmin() && $actor->getRole() !== Role::SuperAdmin) {
            return false;
        }

        $permission = $exporter->sensitive() ? Permission::ExportsSensitive : Permission::ExportsLists;

        return $actor->checkPermissionTo($permission->value) && $exporter->canView($actor);
    }

    public function download(User $actor, ReportRun $run): bool
    {
        if ($run->requested_by !== $actor->id) {
            return false;
        }

        return match ($run->report_type) {
            ReportType::ListExport => ($exporter = ListExporters::find((string) ($run->parameters['list'] ?? ''))) !== null && $this->exportList($actor, $exporter),
            // Runs requested before Phase 11E: the incidents exporter's rule.
            ReportType::IncidentList => ($exporter = ListExporters::find('incidents')) !== null && $this->exportList($actor, $exporter),
            default => $this->generate($actor, $run->report_type),
        };
    }

    public function view(User $actor, ReportRun $run): bool
    {
        return $run->requested_by === $actor->id;
    }

    public function schedule(User $actor, ReportType $type): bool
    {
        return $type->schedulable()
            && $actor->checkPermissionTo(Permission::ReportsSchedule->value)
            && $this->generate($actor, $type);
    }

    public function manageSchedule(User $actor, ReportSchedule $schedule): bool
    {
        return $schedule->user_id === $actor->id && $actor->checkPermissionTo(Permission::ReportsSchedule->value);
    }
}
