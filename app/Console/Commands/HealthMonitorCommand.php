<?php

namespace App\Console\Commands;

use App\Support\Health\HealthCheck;
use App\Support\Health\SystemAlerts;
use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * Runs every health check and raises or clears system alerts (Phase 8C).
 * Scheduled every minute. Checks are registered under the
 * `buffalo.health_checks` container tag (AppServiceProvider).
 */
class HealthMonitorCommand extends Command
{
    protected $signature = 'buffalo:health:monitor';

    protected $description = 'Run health checks and raise or clear system alerts';

    public function handle(Container $container, SystemAlerts $alerts): int
    {
        $failed = false;

        /** @var HealthCheck $check */
        foreach ($container->tagged('buffalo.health_checks') as $check) {
            try {
                $result = $alerts->sync($check->prefix(), $check->conditions());
            } catch (Throwable $e) {
                // One broken check must not stop the others.
                $this->components->error($check::class.': '.$e->getMessage());
                report($e);
                $failed = true;

                continue;
            }

            foreach ($result['raised'] as $key) {
                $this->components->warn("Raised {$key}");
            }
            foreach ($result['cleared'] as $key) {
                $this->components->info("Cleared {$key}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
