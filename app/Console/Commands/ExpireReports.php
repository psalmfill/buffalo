<?php

namespace App\Console\Commands;

use App\Enums\ReportRunStatus;
use App\Models\ReportRun;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Scheduled hourly (Phase 7D): deletes report files past their expiry and
 * marks the runs expired. The run records remain.
 */
class ExpireReports extends Command
{
    protected $signature = 'buffalo:expire-reports';

    protected $description = 'Delete expired report files and mark their runs expired';

    public function handle(): int
    {
        $disk = Storage::disk('reports');
        $expired = 0;

        ReportRun::query()->where('status', ReportRunStatus::Completed->value)->where('expires_at', '<=', now())
            ->chunkById(200, function ($runs) use ($disk, &$expired): void {
                foreach ($runs as $run) {
                    /** @var ReportRun $run */
                    if ($run->file_path !== null) {
                        $disk->delete($run->file_path);
                    }
                    $run->forceFill(['status' => ReportRunStatus::Expired, 'file_path' => null])->save();
                    $expired++;
                }
            });

        $this->components->info("Reports expired: {$expired}.");

        return self::SUCCESS;
    }
}
