<?php

namespace App\Console\Commands;

use App\Enums\EvidenceScanStatus;
use App\Jobs\ProcessEvidenceJob;
use App\Models\CoverageReportAttachment;
use App\Models\IncidentEvidence;
use Illuminate\Console\Command;

/**
 * Queues a malware scan for every quarantined evidence item (Phase 8B.4):
 * uploads accepted while the scanner was unavailable, large uploads whose
 * job did not finish, and files stored before scanning existed. Scheduled
 * every five minutes; each job runs as the item's uploader.
 */
class ScanQuarantinedEvidenceCommand extends Command
{
    protected $signature = 'buffalo:malware:scan-quarantined';

    protected $description = 'Queue a malware scan for quarantined evidence';

    public function handle(): int
    {
        $count = 0;

        // Console commands run in the system context (CLAUDE.md 4.6).
        IncidentEvidence::query()
            ->where('scan_status', EvidenceScanStatus::Quarantined->value)
            ->select(['id', 'uploaded_by'])
            ->orderBy('id')
            ->each(function (IncidentEvidence $evidence) use (&$count): void {
                ProcessEvidenceJob::dispatch($evidence->id, $evidence->uploaded_by);
                $count++;
            });

        // Security report attachments (Phase 11G).
        CoverageReportAttachment::query()
            ->where('scan_status', EvidenceScanStatus::Quarantined->value)
            ->select(['id', 'uploaded_by'])
            ->orderBy('id')
            ->each(function (CoverageReportAttachment $attachment) use (&$count): void {
                ProcessEvidenceJob::dispatch($attachment->id, $attachment->uploaded_by, CoverageReportAttachment::class);
                $count++;
            });

        $this->components->info("{$count} quarantined evidence items and report attachments queued for scanning.");

        return self::SUCCESS;
    }
}
