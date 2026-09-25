<?php

namespace App\Models\Concerns;

use App\Enums\AccessAction;
use App\Models\RecordAccessLog;
use App\Support\Audit\RecordAccessLogger;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Marks a model as access-logged: every read of a record is written to
 * record_access_logs (CLAUDE.md section 6). Controllers call
 * $record->recordAccess() after authorising, when showing, exporting,
 * printing or downloading the record.
 */
trait LogsReads
{
    public function recordAccess(AccessAction $action = AccessAction::View, ?string $context = null): RecordAccessLog
    {
        return app(RecordAccessLogger::class)->log($this, $action, $context);
    }

    /**
     * @return MorphMany<RecordAccessLog, $this>
     */
    public function accessLogs(): MorphMany
    {
        return $this->morphMany(RecordAccessLog::class, 'subject');
    }
}
