<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Spatie\Activitylog\Actions\LogActivityAction;

/**
 * Every activity log entry passes through here (config/activitylog.php):
 * - excluded attributes (AuditExclusions) are removed from the recorded
 *   changes and from the properties, whatever logged them;
 * - the request IP address and user agent are added to the properties.
 */
class BuffaloLogActivityAction extends LogActivityAction
{
    protected function transformChanges(Model $activity): void
    {
        $changes = $activity->getAttribute('attribute_changes');

        if ($changes instanceof Collection) {
            $activity->setAttribute('attribute_changes', $changes->map(
                fn ($values) => is_array($values) ? AuditExclusions::scrub($values) : $values,
            ));
        }

        $properties = $activity->getAttribute('properties');
        $properties = $properties instanceof Collection ? $properties->all() : (array) ($properties ?? []);

        $activity->setAttribute('properties', collect([
            ...AuditExclusions::scrub($properties),
            'ip_address' => request()->ip(),
            'user_agent' => Str::limit((string) request()->userAgent(), 500, ''),
        ]));
    }
}
