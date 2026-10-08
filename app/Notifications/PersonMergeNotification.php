<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\User;

/**
 * Person merges (Phase 10A). PAYLOAD RULE: no names and no identifying
 * details; the link is the survivor's page, or the request, which are
 * access-checked when opened.
 */
final class PersonMergeNotification extends BuffaloNotification
{
    public const MERGED = 'merged';

    public const UNDONE = 'undone';

    public const REQUESTED = 'requested';

    public const DECLINED = 'declined';

    public const ACCESS_REQUEST_CANCELLED = 'access_request_cancelled';

    public const MERGE_REQUEST_CANCELLED = 'merge_request_cancelled';

    public function __construct(
        public readonly string $kind,
        public readonly string $path,
    ) {}

    public function type(): string
    {
        return 'person_merge_'.$this->kind;
    }

    public function title(User $notifiable): string
    {
        return match ($this->kind) {
            self::MERGED => 'Duplicate person records merged',
            self::UNDONE => 'Person merge undone',
            self::REQUESTED => 'Merge of duplicate persons requested',
            self::DECLINED => 'Merge request declined',
            self::ACCESS_REQUEST_CANCELLED => 'Access request cancelled',
            default => 'Merge request cancelled',
        };
    }

    public function body(User $notifiable): string
    {
        return match ($this->kind) {
            self::MERGED => 'Two records of the same person in your jurisdiction were merged into one.',
            self::UNDONE => 'A merge of two person records in your jurisdiction was undone; both records exist again.',
            self::REQUESTED => 'An officer asked for two person records to be merged. Review the request.',
            self::DECLINED => 'Your request to merge two person records was declined.',
            self::ACCESS_REQUEST_CANCELLED => 'Your access request was cancelled because the record was merged into another record. You can search and request access again.',
            default => 'A merge request was cancelled because one of the records was merged.',
        };
    }

    public function url(User $notifiable): string
    {
        return $this->path;
    }

    public function severity(): NotificationSeverity
    {
        return in_array($this->kind, [self::DECLINED, self::ACCESS_REQUEST_CANCELLED, self::MERGE_REQUEST_CANCELLED], true)
            ? NotificationSeverity::Warning
            : NotificationSeverity::Info;
    }
}
