<?php

namespace App\Http\Presenters;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

/**
 * Audit entries for the browser: causer and subject by name/ULID, never by
 * numeric id.
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
            'old' => $changes['old'] ?? null,
            'new' => $changes['attributes'] ?? null,
            'properties' => collect($properties)->except(['ip_address', 'user_agent'])->all(),
            'ip_address' => $properties['ip_address'] ?? null,
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
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
