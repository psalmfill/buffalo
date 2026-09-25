<?php

namespace App\Support\Dashboard;

use Laravel\Horizon\Contracts\MasterSupervisorRepository;

/**
 * Horizon status, as `php artisan horizon:status` reports it.
 */
final class QueueHealth
{
    public function __construct(private readonly MasterSupervisorRepository $masters) {}

    /**
     * @return 'running'|'paused'|'inactive'
     */
    public function status(): string
    {
        $masters = $this->masters->all();

        if ($masters === []) {
            return 'inactive';
        }

        return collect($masters)->contains(fn (object $master): bool => ($master->status ?? null) === 'paused')
            ? 'paused'
            : 'running';
    }
}
