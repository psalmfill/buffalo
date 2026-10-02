<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Enums\ReportType;
use App\Enums\Role;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\User;

/**
 * Reports (Phase 7D).
 *
 * | Ability  | Rule                                                                 |
 * |----------|----------------------------------------------------------------------|
 * | generate | reports.generate (PDF) or reports.export_data (incident list) AND the |
 * |          | module's view permission; monthly summaries: DG, DDG, SD, DSD        |
 * | download | the requester only (nobody else, not even the DG)                    |
 * | schedule | reports.schedule AND generate, for SITREP, weekly and monthly only    |
 *
 * Single-record reports (incident, LGA profile, coverage report) also need
 * `view` on that record, checked by ReportParameters.
 */
final class ReportPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::ReportsGenerate->value)
            || $actor->checkPermissionTo(Permission::ReportsExportData->value);
    }

    public function generate(User $actor, ReportType $type): bool
    {
        if (! $actor->checkPermissionTo($type->permission()->value)) {
            return false;
        }

        return match ($type) {
            ReportType::Sitrep, ReportType::Weekly, ReportType::Incident, ReportType::IncidentList => $actor->checkPermissionTo(Permission::IncidentsView->value),
            ReportType::Monthly => $actor->checkPermissionTo(Permission::IncidentsView->value)
                && in_array($actor->getRole(), [Role::DirectorGeneral, Role::DeputyDirectorGeneral, Role::StateDirector, Role::DeputyStateDirector], true),
            ReportType::LgaProfile => $actor->checkPermissionTo(Permission::LgasView->value),
            ReportType::Coverage => $actor->checkPermissionTo(Permission::CoverageView->value),
            ReportType::Analytics => $actor->checkPermissionTo(Permission::AnalyticsView->value),
        };
    }

    public function download(User $actor, ReportRun $run): bool
    {
        return $run->requested_by === $actor->id && $this->generate($actor, $run->report_type);
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
