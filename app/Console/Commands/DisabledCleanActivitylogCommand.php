<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Replaces spatie/laravel-activitylog's `activitylog:clean` (bound in
 * AppServiceProvider). Audit records are never deleted (CLAUDE.md
 * section 6); the retention policy is decided in Phase 8. The database
 * trigger on activity_log would refuse the deletes anyway.
 */
class DisabledCleanActivitylogCommand extends Command
{
    protected $signature = 'activitylog:clean {log? : Ignored} {--days= : Ignored} {--force : Ignored}';

    protected $description = 'Disabled: BUFFALO audit records are never deleted';

    protected $hidden = true;

    public function handle(): int
    {
        $this->components->error('activitylog:clean is disabled. Audit records are never deleted; retention is decided in Phase 8.');

        return self::FAILURE;
    }
}
