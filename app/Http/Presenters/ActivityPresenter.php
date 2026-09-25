<?php

namespace App\Http\Presenters;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Audit entries for the browser: causer and subject by name/ULID, never by
 * numeric id. Numeric keys recorded in the changes (id, *_id, *_by) are
 * withheld; the ULID and the named-event labels identify the records.
 */
final class ActivityPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function present(Activity $activity): array
    {
        $changes = $activity->attribute_changes instanceof Collection ? $activity->attribute_changes->all() : [];
        $properties = $activity->properties instanceof Collection ? $activity->properties->all() : [];

        return [
            'log' => $activity->log_name,
            'event' => $activity->event,
            'description' => $activity->description,
            'causer' => self::describe($activity->causer),
            'subject_type' => $activity->subject_type === null ? null : class_basename($activity->subject_type),
            'subject' => self::describe($activity->subject),
            'old' => self::withoutNumericIds($changes['old'] ?? null),
            'new' => self::withoutNumericIds($changes['attributes'] ?? null),
            'properties' => self::withoutNumericIds(collect($properties)->except(['ip_address', 'user_agent'])->all()),
            'ip_address' => $properties['ip_address'] ?? null,
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }

    private static function withoutNumericIds(mixed $values): mixed
    {
        if (! is_array($values)) {
            return $values;
        }

        $kept = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && ($key === 'id' || str_ends_with($key, '_id') || str_ends_with($key, '_by'))) {
                continue;
            }

            $kept[$key] = self::withoutNumericIds($value);
        }

        return $kept;
    }

    /**
     * @return array{label: string, ulid: string|null, type: string}|null
     */
    private static function describe(?Model $model): ?array
    {
        if ($model === null) {
            return null;
        }

        $label = $model instanceof User
            ? $model->name
            : (string) ($model->getAttribute('name') ?? $model->getAttribute('code') ?? class_basename($model));

        $ulid = $model->getAttribute('ulid');

        return ['label' => $label, 'ulid' => is_string($ulid) ? $ulid : null, 'type' => class_basename($model)];
    }
}
