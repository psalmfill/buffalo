<?php

namespace App\Http\Controllers\System;

use App\Http\Controllers\Controller;
use App\Support\Health\SystemHealthReport;
use Inertia\Inertia;
use Inertia\Response;

/**
 * System health (Phase 8C.4): services, queues, scheduler, backups,
 * storage, audit chains, key fingerprints, certificate and open alerts.
 * Super admins (system.settings); no intelligence content.
 */
class SystemHealthController extends Controller
{
    public function index(SystemHealthReport $report): Response
    {
        $this->authorize('viewSystemHealth');

        return Inertia::render('system/health', ['report' => $report->build()]);
    }
}
