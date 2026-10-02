<?php

namespace App\Support\Audit;

use App\Enums\AccessAction;
use App\Models\RecordAccessLog;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Records that the current user read a sensitive record. Controllers call
 * $record->recordAccess() (LogsReads) when showing, exporting, printing or
 * downloading such a record.
 */
final class RecordAccessLogger
{
    public function __construct(private readonly Request $request) {}

    public function log(Model $subject, AccessAction $action = AccessAction::View, ?string $context = null): RecordAccessLog
    {
        // A queued job acting for a user (e.g. report generation, Phase 7D)
        // has no request user: log the user whose context it runs in.
        $user = $this->request->user() ?? app(JurisdictionContext::class)->currentUser();

        return RecordAccessLog::query()->create([
            'user_id' => $user?->getAuthIdentifier(),
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'action' => $action,
            'context' => $context === null ? null : Str::limit($context, 100, ''),
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 500, ''),
        ]);
    }
}
