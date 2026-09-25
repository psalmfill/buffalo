<?php

namespace App\Notifications;

use App\Enums\NotificationSeverity;
use App\Models\Lga;
use App\Models\User;

/**
 * A queued community import has finished (or failed).
 */
final class CommunityImportFinished extends BuffaloNotification
{
    /**
     * @param  array{imported: int, skipped: int, errors: int}|null  $result  null when the import failed
     */
    public function __construct(
        public readonly Lga $lga,
        public readonly ?array $result,
    ) {}

    public function type(): string
    {
        return $this->result === null ? 'community_import_failed' : 'community_import_finished';
    }

    public function title(User $notifiable): string
    {
        return $this->result === null ? 'Community import failed' : 'Community import finished';
    }

    public function body(User $notifiable): string
    {
        if ($this->result === null) {
            return "The community import for {$this->lga->name} LGA failed. Nothing was imported; please try again.";
        }

        return "{$this->result['imported']} communities imported into {$this->lga->name} LGA"
            ." ({$this->result['skipped']} skipped, {$this->result['errors']} with errors).";
    }

    public function url(User $notifiable): string
    {
        return '/lgas/'.$this->lga->ulid.'?tab=communities';
    }

    public function severity(): NotificationSeverity
    {
        return $this->result === null ? NotificationSeverity::Warning : NotificationSeverity::Info;
    }
}
