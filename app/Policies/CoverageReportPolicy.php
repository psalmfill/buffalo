<?php

namespace App\Policies;

use App\Enums\CoverageReportStatus;
use App\Enums\Permission;
use App\Enums\Role;
use App\Models\CoverageReport;
use App\Models\Lga;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;
use App\Support\Jurisdiction\JurisdictionOptions;

/**
 * Coverage reports (Phase 4A): permission, the report's own jurisdiction
 * AND classification, then the workflow:
 *
 * - create: `coverage.create` for an LGA within the user's jurisdiction
 *   (an LGA chief's own LGA; any LGA of a State Director's or Deputy's
 *   state, e.g. when the chief post is vacant);
 * - edit and submit a draft or returned report: its creator, or the State
 *   Director or Deputy of the state;
 * - return and mark reviewed a submitted report: `coverage.review`;
 * - discard: the creator, drafts only;
 * - a reviewed report is locked for everyone.
 */
final class CoverageReportPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
        private readonly JurisdictionOptions $options,
    ) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::CoverageView->value);
    }

    public function view(User $actor, CoverageReport $report): bool
    {
        return $this->viewAny($actor) && $this->reachable($actor, $report);
    }

    public function create(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::CoverageCreate->value)
            && $this->options->lgaQuery($actor)->active()->exists();
    }

    public function createFor(User $actor, Lga $lga): bool
    {
        return $actor->checkPermissionTo(Permission::CoverageCreate->value)
            && $this->options->lgaQuery($actor)->active()->whereKey($lga->id)->exists();
    }

    public function update(User $actor, CoverageReport $report): bool
    {
        return $report->status->isEditable()
            && $actor->checkPermissionTo(Permission::CoverageCreate->value)
            && $this->reachable($actor, $report)
            && ($report->created_by === $actor->id || $this->isStateLead($actor, $report));
    }

    public function submit(User $actor, CoverageReport $report): bool
    {
        return $this->update($actor, $report);
    }

    public function review(User $actor, CoverageReport $report): bool
    {
        return $report->status === CoverageReportStatus::Submitted
            && $actor->checkPermissionTo(Permission::CoverageReview->value)
            && $this->reachable($actor, $report);
    }

    public function delete(User $actor, CoverageReport $report): bool
    {
        return $report->status === CoverageReportStatus::Draft
            && $report->created_by === $actor->id
            && $this->reachable($actor, $report);
    }

    /** The State Director or Deputy of the report's state. */
    private function isStateLead(User $actor, CoverageReport $report): bool
    {
        return in_array($actor->getRole(), [Role::StateDirector, Role::DeputyStateDirector], true)
            && $actor->state_id === $report->state_id;
    }

    private function reachable(User $actor, CoverageReport $report): bool
    {
        return $this->jurisdiction->canAccessOwn($actor, $report) && $this->classification->canSee($actor, $report);
    }
}
