<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * An audit hash chain failed verification (Phase 8A.4): an audit record
 * was altered or removed outside the application. Sent to super admins
 * and the DG. Payload: the table name and row ID only.
 */
final class AuditChainBroken extends BuffaloNotification
{
    public function __construct(
        public readonly string $table,
        public readonly ?int $rowId,
    ) {}

    public function type(): string
    {
        return 'audit_chain_broken';
    }

    public function title(User $notifiable): string
    {
        return 'Audit records may have been tampered with';
    }

    public function body(User $notifiable): string
    {
        return "The audit integrity check failed for {$this->table}".($this->rowId === null ? '' : " at row {$this->rowId}").'. Investigate before trusting these records.';
    }

    public function severity(): NotificationSeverity
    {
        return NotificationSeverity::Critical;
    }

    /** No table or row in mail (Phase 8B.5); the notification has them. */
    public function mailBody(User $notifiable): string
    {
        return 'An audit integrity check failed. Sign in to see which records are affected, and investigate before trusting them.';
    }

    public function allowsMail(): bool
    {
        return true;
    }
}
