<?php

namespace App\Console\Commands;

use App\Enums\AccessRequestStatus;
use App\Enums\RequestUrgency;
use App\Models\RecordAccessRequest;
use App\Notifications\AccessRequestEscalated;
use App\Notifications\AccessRequestExpired;
use App\Support\Audit\AuditLog;
use App\Support\Jurisdiction\AccessRequestApprovers;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

/**
 * Every 5 minutes (Phase 3C): pending requests escalate to national
 * oversight after escalate_urgent_minutes (urgent) or
 * escalate_normal_minutes (normal); escalated requests expire after
 * expire_escalated_days. System context (console).
 */
class EscalateAccessRequests extends Command
{
    protected $signature = 'buffalo:escalate-access-requests';

    protected $description = 'Escalate undecided access requests and expire stale escalated ones';

    public function handle(AccessRequestApprovers $approvers): int
    {
        $now = CarbonImmutable::now();
        $config = config('buffalo.cross_jurisdiction');
        $escalated = 0;
        $expired = 0;

        $due = RecordAccessRequest::query()
            ->with('owningState')
            ->where('status', AccessRequestStatus::Pending->value)
            ->where(fn ($q) => $q
                ->where(fn ($w) => $w->where('urgency', RequestUrgency::Urgent->value)->where('created_at', '<=', $now->subMinutes((int) $config['escalate_urgent_minutes'])))
                ->orWhere(fn ($w) => $w->where('urgency', RequestUrgency::Normal->value)->where('created_at', '<=', $now->subMinutes((int) $config['escalate_normal_minutes']))))
            ->get();

        foreach ($due as $request) {
            $request->forceFill(['status' => AccessRequestStatus::Escalated, 'escalated_at' => $now])->save();
            AuditLog::event('access_request_escalated', 'Access request escalated: not decided in time', $request);
            Notification::send($approvers->deciders($request, nationalOnly: true), new AccessRequestEscalated(
                $request->ulid,
                $request->owningState->name.' State',
                $request->urgency === RequestUrgency::Urgent,
            ));
            $escalated++;
        }

        $stale = RecordAccessRequest::query()
            ->with(['owningState', 'requester'])
            ->where('status', AccessRequestStatus::Escalated->value)
            ->where('escalated_at', '<=', $now->subDays((int) $config['expire_escalated_days']))
            ->get();

        foreach ($stale as $request) {
            $request->forceFill(['status' => AccessRequestStatus::Expired])->save();
            AuditLog::event('access_request_expired', 'Access request expired: never decided', $request);
            $request->requester->notify(new AccessRequestExpired($request->ulid, $request->owningState->name.' State'));
            $expired++;
        }

        $this->components->info("Access requests escalated: {$escalated}; expired: {$expired}.");

        return self::SUCCESS;
    }
}
