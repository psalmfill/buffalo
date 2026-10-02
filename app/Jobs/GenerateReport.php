<?php

namespace App\Jobs;

use App\Enums\ReportRunStatus;
use App\Events\ReportRunUpdated;
use App\Models\ReportRun;
use App\Models\User;
use App\Notifications\ReportReady;
use App\Services\Reports\ReportBuilders;
use App\Support\Jurisdiction\JurisdictionContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Generates one report run (Phase 7D) strictly through the requester's
 * scopes as they are now: the builder runs inside
 * JurisdictionContext::actingAs($requester). A deactivated, deleted or
 * locked requester's run fails without building anything.
 *
 * The file goes to the private `reports` disk as runs/{ulid}.{ext} with
 * its SHA-256 (checked again on every download) and expires after
 * `buffalo.reports.expiry_days`. A failure stores a generic message for
 * the user; the log carries only the run, type and exception location,
 * never parameters, messages or record content.
 */
final class GenerateReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $runId)
    {
        $this->onConnection((string) config('buffalo.reports.queue_connection'))->onQueue('reports');
    }

    public function handle(JurisdictionContext $context): void
    {
        $run = ReportRun::query()->with('requester')->find($this->runId);

        if ($run === null || $run->status !== ReportRunStatus::Queued) {
            return;
        }

        /** @var User|null $user */
        $user = User::query()->withTrashed()->find($run->requested_by);

        if ($user === null || $user->isAccessBlocked()) {
            $this->fail($run, 'This report was not generated because the account that requested it is not active.');

            return;
        }

        $this->update($run, ['status' => ReportRunStatus::Running, 'started_at' => now()], $user);

        try {
            $output = $context->actingAs($user, fn () => ReportBuilders::for($run->report_type)->build($run, $user));
            $path = 'runs/'.$run->ulid.'.'.$run->format->value;
            $stream = fopen($output->path, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('The generated report file could not be read.');
            }
            Storage::disk('reports')->writeStream($path, $stream);
            fclose($stream);
            $completed = CarbonImmutable::now();

            $this->update($run, [
                'status' => ReportRunStatus::Completed,
                'classification' => $output->classification,
                'file_path' => $path,
                'sha256' => (string) hash_file('sha256', $output->path),
                'size_bytes' => (int) filesize($output->path),
                'row_count' => $output->rows,
                'page_count' => $output->pages,
                'completed_at' => $completed,
                'expires_at' => $completed->addDays((int) config('buffalo.reports.expiry_days')),
            ], $user);
            @unlink($output->path);
        } catch (Throwable $e) {
            Log::error('Report generation failed', [
                'run' => $run->ulid,
                'type' => $run->report_type->value,
                'exception' => $e::class,
                'at' => basename($e->getFile()).':'.$e->getLine(),
            ]);
            $this->fail($run, 'This report could not be generated. Please try again; if it keeps failing, contact your administrator.', $user);

            return;
        }

        if ($run->report_schedule_id !== null) {
            $user->notify(new ReportReady($run));
        }
    }

    private function fail(ReportRun $run, string $message, ?User $user = null): void
    {
        $this->update($run, ['status' => ReportRunStatus::Failed, 'error_message' => $message, 'completed_at' => now()], $user ?? $run->requester);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function update(ReportRun $run, array $values, ?User $user): void
    {
        $run->forceFill($values)->save();

        if ($user !== null) {
            ReportRunUpdated::dispatch($run->ulid, $run->status->value, $user->ulid);
        }
    }
}
