<?php

namespace App\Enums;

/** Report run status (Phase 7D). */
enum ReportRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Expired = 'expired';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
