<?php

namespace App\Models\Concerns;

use App\Support\Audit\AuditExclusions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Records creates, updates, deletes and restores in the activity log (log
 * "model"): only changed attributes, never the globally excluded ones
 * (AuditExclusions), with the acting user as causer and the request IP and
 * user agent (BuffaloLogActivityAction).
 *
 * Models may add their own exclusions by overriding auditExcept().
 */
trait LogsBuffaloActivity
{
    use LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('model')
            ->logAll()
            ->logExcept([...AuditExclusions::NAMES, 'created_at', 'updated_at', ...$this->auditExcept()])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return list<string>
     */
    protected function auditExcept(): array
    {
        return [];
    }
}
