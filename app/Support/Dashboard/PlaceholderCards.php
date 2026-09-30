<?php

namespace App\Support\Dashboard;

/**
 * The operational cards on the national, state and LGA dashboards. Until
 * their phase delivers the data they show "Available from Phase N"; a phase
 * replaces a placeholder by giving its card real content.
 */
final class PlaceholderCards
{
    /**
     * @return list<array{key: string, title: string, scope: string, availableFromPhase: int}>
     */
    public static function for(string $scope): array
    {
        return [
            ['key' => 'incidents', 'title' => 'Incidents (last 30 days)', 'scope' => $scope, 'availableFromPhase' => 5],
            ['key' => 'flashpoints', 'title' => 'Active flashpoints', 'scope' => $scope, 'availableFromPhase' => 6],
            ['key' => 'escalations', 'title' => 'Pending escalations', 'scope' => $scope, 'availableFromPhase' => 5],
        ];
    }
}
