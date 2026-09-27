<?php

namespace App\Console\Commands;

use App\Models\RecordAccessGrant;
use App\Notifications\AccessGrantChanged;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Every 15 minutes (Phase 3C): reminds holders grant_reminder_hours before
 * a grant expires, and records and announces grants that have expired.
 * Access itself ends at expires_at (the resolver only admits active
 * grants); this job does the telling and the audit trail.
 */
class ExpireAccessGrants extends Command
{
    protected $signature = 'buffalo:expire-access-grants';

    protected $description = 'Remind holders of expiring grants and end expired ones';

    public function handle(): int
    {
        $now = CarbonImmutable::now();
        $hours = (int) config('buffalo.cross_jurisdiction.grant_reminder_hours', 24);

        $expiring = RecordAccessGrant::query()->active()->with(['user', 'request'])
            ->whereNull('expiry_reminded_at')
            ->where('expires_at', '<=', $now->addHours($hours))
            ->get();

        foreach ($expiring as $grant) {
            $grant->forceFill(['expiry_reminded_at' => $now])->save();
            $grant->user->notify(new AccessGrantChanged($grant->request->ulid, 'expiring', $grant->expires_at->toDayDateTimeString()));
        }

        $ended = RecordAccessGrant::query()->with(['user', 'request'])
            ->whereNull('revoked_at')
            ->whereNull('expiry_notified_at')
            ->where('expires_at', '<=', $now)
            ->get();

        foreach ($ended as $grant) {
            $grant->forceFill(['expiry_notified_at' => $now])->save();
            AuditLog::event('access_grant_expired', 'Temporary access expired', $grant->request, ['grant' => $grant->ulid]);
            $grant->user->notify(new AccessGrantChanged($grant->request->ulid, 'expired', $grant->expires_at->toDayDateTimeString()));
        }

        $this->components->info("Grants reminded: {$expiring->count()}; ended: {$ended->count()}.");

        return self::SUCCESS;
    }
}
