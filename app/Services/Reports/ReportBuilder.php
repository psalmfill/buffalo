<?php

namespace App\Services\Reports;

use App\Models\ReportRun;
use App\Models\User;

/**
 * Builds one report type (Phase 7D). Runs inside
 * JurisdictionContext::actingAs($requester): every query goes through the
 * requester's scopes as they are at generation time.
 */
interface ReportBuilder
{
    public function build(ReportRun $run, User $user): ReportOutput;
}
