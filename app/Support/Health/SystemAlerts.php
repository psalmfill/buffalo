<?php

namespace App\Support\Health;

use App\Enums\Role;
use App\Notifications\BuffaloNotification;
use App\Notifications\SystemAlert;
use App\Support\Notifications\NotifyRole;
use Illuminate\Support\Facades\DB;

/**
 * Deduplicated operational alerts (Phase 8C). A condition is notified when
 * it is first raised (and again if it escalates from warning to critical),
 * and once when it clears; never repeatedly while it lasts.
 */
final class SystemAlerts
{
    public function __construct(private readonly NotifyRole $notify) {}

    /**
     * Applies one check's current conditions: raises new ones, clears its
     * own (by prefix) that are gone.
     *
     * @param  list<Condition>  $conditions
     * @return array{raised: list<string>, cleared: list<string>}
     */
    public function sync(string $prefix, array $conditions): array
    {
        $raised = [];
        $current = [];

        foreach ($conditions as $condition) {
            $current[] = $condition->key;
            if ($this->raise($condition)) {
                $raised[] = $condition->key;
            }
        }

        $cleared = [];
        $open = DB::table('system_alerts')->whereNull('cleared_at')->where('key', 'like', addcslashes($prefix, '%_\\').'%')
            ->whereNotIn('key', $current)->get(['id', 'key', 'severity', 'title']);

        foreach ($open as $row) {
            DB::table('system_alerts')->where('id', $row->id)->update(['cleared_at' => now()]);
            $level = $row->severity === 'critical' ? 'critical' : 'warning';
            $this->send(new SystemAlert((string) $row->key, $level, (string) $row->title, '', cleared: true), str_starts_with((string) $row->key, 'audit.'));
            $cleared[] = (string) $row->key;
        }

        return ['raised' => $raised, 'cleared' => $cleared];
    }

    /** @return bool whether a notification was sent */
    private function raise(Condition $condition): bool
    {
        $open = DB::table('system_alerts')->whereNull('cleared_at')->where('key', $condition->key)->first(['id', 'severity']);

        if ($open !== null) {
            $escalated = $open->severity === 'warning' && $condition->severity === 'critical';
            DB::table('system_alerts')->where('id', $open->id)->update([
                'last_seen_at' => now(), 'detail' => $condition->detail, 'title' => $condition->title,
                'severity' => $escalated ? 'critical' : $open->severity,
            ]);

            if (! $escalated) {
                return false;
            }
        } else {
            $inserted = DB::table('system_alerts')->insertOrIgnore([
                'key' => $condition->key, 'severity' => $condition->severity, 'title' => $condition->title,
                'detail' => $condition->detail, 'raised_at' => now(), 'last_seen_at' => now(),
            ]);
            if ($inserted === 0) {
                return false; // Raised concurrently by another run.
            }
        }

        $this->send($condition->notification ?? new SystemAlert($condition->key, $condition->severity, $condition->title, $condition->detail), $condition->alsoDirectorGeneral);

        return true;
    }

    private function send(BuffaloNotification $alert, bool $alsoDirectorGeneral): void
    {
        $this->notify->send($alert, Role::SuperAdmin);

        if ($alsoDirectorGeneral) {
            $this->notify->send($alert, Role::DirectorGeneral);
        }
    }
}
