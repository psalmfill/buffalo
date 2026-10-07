<?php

namespace App\Support\GoLive;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * The production start-up checks (Phase 8A/9A) as the containers run them
 * at boot, for the go-live check (Phase 9B.6).
 */
class BootChecks
{
    public const COMMANDS = ['buffalo:production:check', 'buffalo:secrets:check', 'buffalo:keys:verify'];

    /**
     * @return list<string> the commands that fail
     */
    public function failing(): array
    {
        $failed = [];

        foreach (self::COMMANDS as $command) {
            try {
                if (Artisan::call($command, ['--no-interaction' => true], new BufferedOutput) !== 0) {
                    $failed[] = $command;
                }
            } catch (Throwable) {
                $failed[] = $command;
            }
        }

        return $failed;
    }
}
