<?php

namespace App\Actions\AccessRequests;

use App\Enums\AccessRequestStatus;
use App\Models\RecordAccessRequest;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * The requester withdraws an open request (Phase 3C).
 */
final class CancelAccessRequest
{
    public function __invoke(RecordAccessRequest $request, User $requester): void
    {
        DB::transaction(function () use ($request, $requester): void {
            $request->forceFill(['status' => AccessRequestStatus::Cancelled])->save();
            AuditLog::event('access_request_cancelled', 'Access request cancelled', $request, [], $requester);
        });
    }
}
