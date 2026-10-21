<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * A sensitive list was exported (Phase 11E): sent at once, as critical, to
 * the DG and the DDGs covering the requester. It names the requester, the
 * list, the row count and the stated reason; never any record content.
 */
final class SensitiveExportAlert extends BuffaloNotification
{
    public function __construct(
        public readonly string $officer,
        public readonly string $role,
        public readonly string $jurisdiction,
        public readonly string $list,
        public readonly int $rows,
        public readonly string $reason,
        public readonly string $reference,
    ) {}

    public function type(): string
    {
        return 'sensitive_export';
    }

    public function title(User $notifiable): string
    {
        return "Sensitive list exported: {$this->list}";
    }

    public function body(User $notifiable): string
    {
        return "{$this->officer} ({$this->role}, {$this->jurisdiction}) requested an export of the {$this->list} list: "
            .number_format($this->rows).' '.($this->rows === 1 ? 'row' : 'rows').". Reason given: \"{$this->reason}\". Reference {$this->reference}.";
    }

    public function url(User $notifiable): string
    {
        return '/audit/exports';
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Critical;
    }
}
