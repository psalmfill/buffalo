<?php

namespace App\Support\Taskings;

use App\Enums\TaskingRecipientStatus;
use App\Models\TaskingRecipient;
use Carbon\CarbonImmutable;

/**
 * Progress counts per tasking (Phase 11H), for the directors' lists and
 * dashboard: "12 of 31 read · 5 acknowledged · 2 completed · 3 overdue".
 * Read counts every LGA that has at least read it (acknowledged and
 * completed included), and so on. Computed through the recipients' own
 * scopes, so it is only meaningful for users who see every recipient row
 * (directors and national users; never shown to LGA chiefs).
 *
 * @phpstan-type Progress array{total: int, read: int, acknowledged: int, completed: int, overdue: int}
 */
final class TaskingProgress
{
    /**
     * @param  list<int>  $taskingIds
     * @return array<int, Progress> keyed by tasking id
     */
    public static function for(array $taskingIds, ?CarbonImmutable $now = null): array
    {
        if ($taskingIds === []) {
            return [];
        }

        $now ??= CarbonImmutable::now();
        $done = TaskingRecipientStatus::Completed->value;

        $rows = TaskingRecipient::query()
            ->join('taskings', 'taskings.id', '=', 'tasking_recipients.tasking_id')
            ->whereIn('tasking_recipients.tasking_id', $taskingIds)
            ->groupBy('tasking_recipients.tasking_id')
            ->toBase()
            ->select('tasking_recipients.tasking_id')
            ->selectRaw('count(*) AS total')
            ->selectRaw("count(*) FILTER (WHERE tasking_recipients.status <> 'delivered') AS read")
            ->selectRaw("count(*) FILTER (WHERE tasking_recipients.status IN ('acknowledged', 'completed')) AS acknowledged")
            ->selectRaw('count(*) FILTER (WHERE tasking_recipients.status = ?) AS completed', [$done])
            ->selectRaw("count(*) FILTER (WHERE tasking_recipients.status <> ? AND taskings.status = 'sent' AND taskings.response_due_at IS NOT NULL AND taskings.response_due_at <= ?) AS overdue", [$done, $now])
            ->get();

        $progress = [];

        foreach ($rows as $row) {
            $progress[(int) $row->tasking_id] = [
                'total' => (int) $row->total,
                'read' => (int) $row->read,
                'acknowledged' => (int) $row->acknowledged,
                'completed' => (int) $row->completed,
                'overdue' => (int) $row->overdue,
            ];
        }

        return $progress;
    }

    /**
     * @param  Progress  $p
     */
    public static function line(array $p): string
    {
        $parts = ["{$p['read']} of {$p['total']} read", "{$p['acknowledged']} acknowledged", "{$p['completed']} completed"];

        if ($p['overdue'] > 0) {
            $parts[] = "{$p['overdue']} overdue";
        }

        return implode(' · ', $parts);
    }
}
