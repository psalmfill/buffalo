<?php

namespace App\Support\Exports;

use App\Enums\Role;
use App\Models\ReportRun;
use App\Models\User;
use App\Notifications\SensitiveExportAlert;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Notifications\NotifyRole;

/**
 * Alerts for exports of the sensitive lists (Phase 11E): the DG and the
 * DDGs covering the requester's jurisdiction, at once, whoever asked (a DG
 * exporting is not told about their own export).
 */
final class SensitiveExports
{
    public function __construct(private readonly NotifyRole $notify) {}

    public function alert(ReportRun $run, ListExporter $exporter, User $requester): void
    {
        $notification = new SensitiveExportAlert(
            $requester->name,
            $requester->getRole()?->label() ?? 'No role',
            $requester->jurisdictionLabel(),
            $exporter->label(),
            (int) $run->row_count,
            (string) $run->reason,
            $run->reference(),
        );
        $notMe = fn (User $recipient) => $recipient->id !== $requester->id;

        $this->notify->send($notification, Role::DirectorGeneral, only: $notMe);
        $this->notify->send($notification, Role::DeputyDirectorGeneral, $requester->lga ?? $requester->state, $notMe);
    }
}
