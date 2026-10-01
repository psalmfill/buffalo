<?php

namespace App\Actions\Blackspots;

use App\Models\BlackspotSuggestion;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Dismisses a suggested blackspot with a reason (Phase 6C); the area is not
 * suggested again for `dismiss_days`.
 */
final class DismissBlackspotSuggestion
{
    public function __invoke(BlackspotSuggestion $suggestion, string $reason, User $actor): void
    {
        DB::table('blackspot_suggestions')->where('id', $suggestion->id)->update([
            'status' => 'dismissed',
            'dismiss_reason' => $reason,
            'decided_by' => $actor->id,
            'decided_at' => now(),
        ]);

        AuditLog::event('blackspot_suggestion_dismissed', 'Suggested blackspot dismissed', $suggestion, ['reason' => $reason], $actor);
    }
}
