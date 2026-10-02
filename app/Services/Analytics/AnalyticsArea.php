<?php

namespace App\Services\Analytics;

use App\Models\User;

/**
 * One analytics tab (Phase 7C). Every figure is computed through the
 * viewer's own scopes; incident figures follow the statistics rule.
 */
interface AnalyticsArea
{
    /** Tab key, e.g. "incidents". */
    public function key(): string;

    public function label(): string;

    /** Permission to see the tab (besides analytics.view). */
    public function allows(User $user): bool;

    /**
     * @return list<array<string, mixed>> charts (ChartData::make)
     */
    public function charts(User $user, AnalyticsFilters $filters): array;
}
