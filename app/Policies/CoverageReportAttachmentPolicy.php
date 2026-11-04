<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\CoverageReport;
use App\Models\CoverageReportAttachment;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Security report attachments (Phase 11G): `coverage.view`, the
 * attachment's own jurisdiction and classification, and its report
 * visible. Like the reports, attachments are never grantable or
 * disseminable: no cross-jurisdiction path exists.
 *
 * - Add, edit and remove: whoever may edit the report (a draft or returned
 *   report; CoverageReportPolicy::update). Locked once reviewed.
 * - View and download: anyone who may view it (no new permission); the
 *   download of an original above `restricted` also needs step-up.
 */
final class CoverageReportAttachmentPolicy
{
    public function __construct(
        private readonly JurisdictionGate $jurisdiction,
        private readonly ClassificationGate $classification,
    ) {}

    public function view(User $actor, CoverageReportAttachment $attachment): bool
    {
        // The relation is scoped: a report out of reach loads as null.
        $report = $attachment->report;

        return $report !== null
            && $actor->checkPermissionTo(Permission::CoverageView->value)
            && $this->jurisdiction->canAccessOwn($actor, $attachment)
            && $this->classification->canSee($actor, $attachment)
            && $actor->can('view', $report);
    }

    public function upload(User $actor, CoverageReport $report): bool
    {
        return $actor->can('update', $report);
    }

    public function update(User $actor, CoverageReportAttachment $attachment): bool
    {
        return $this->view($actor, $attachment) && $attachment->report !== null && $actor->can('update', $attachment->report);
    }

    public function delete(User $actor, CoverageReportAttachment $attachment): bool
    {
        return $this->update($actor, $attachment);
    }

    public function download(User $actor, CoverageReportAttachment $attachment): bool
    {
        return $this->view($actor, $attachment);
    }
}
