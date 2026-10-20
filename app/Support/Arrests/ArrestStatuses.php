<?php

namespace App\Support\Arrests;

use App\Enums\ArrestStatus;
use App\Models\Arrest;
use Illuminate\Support\Facades\DB;

/**
 * The arrest's own status (Phase 11C) shows the most common status of its
 * arrested persons; the per-person statuses are the detail. On a tie the
 * current status wins when it is among the tied, else the earliest in the
 * status list.
 */
final class ArrestStatuses
{
    public static function mostCommon(Arrest $arrest, ArrestStatus $current): ArrestStatus
    {
        // Counted on every live row (persons the viewer may not see count too).
        $counts = DB::table('arrest_persons')->where('arrest_id', $arrest->id)->whereNull('deleted_at')
            ->groupBy('status')->selectRaw('status, count(*) AS total')->pluck('total', 'status')
            ->map(fn ($n) => (int) $n);

        if ($counts->isEmpty()) {
            return $current;
        }

        $max = $counts->max();
        $tied = $counts->filter(fn (int $n) => $n === $max)->keys()->all();

        if (in_array($current->value, $tied, true)) {
            return $current;
        }

        foreach (ArrestStatus::cases() as $status) {
            if (in_array($status->value, $tied, true)) {
                return $status;
            }
        }

        return $current;
    }
}
