<?php

namespace App\Actions\Hierarchy\Admin;

use App\Actions\Hierarchy\HierarchyRules;
use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\User;
use App\Models\Ward;
use App\Support\Audit\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Accountability for Administration › Hierarchy: every change needs a
 * reason and is recorded as a named audit event (besides the model's own
 * change log), e.g. "lga_created" or "ward_deactivated".
 */
final class HierarchyChange
{
    public const MAX_REASON = 1000;

    /**
     * @throws ValidationException
     */
    public static function reason(string $reason): string
    {
        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON) {
            throw ValidationException::withMessages(['reason' => 'A reason of at most '.self::MAX_REASON.' characters is required.']);
        }

        return $reason;
    }

    /**
     * @throws ValidationException
     */
    public static function name(string $name, string $field = 'name'): string
    {
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > HierarchyRules::MAX_NAME_LENGTH) {
            throw ValidationException::withMessages([$field => 'A name of at most '.HierarchyRules::MAX_NAME_LENGTH.' characters is required.']);
        }

        return $name;
    }

    /**
     * @param  'created'|'renamed'|'deactivated'|'reactivated'|'capital_changed'  $action
     * @param  array<string, mixed>  $properties
     */
    public static function record(Model $entry, string $action, string $reason, User $actor, array $properties = []): void
    {
        $level = self::level($entry);

        AuditLog::event(
            "{$level}_{$action}",
            sprintf('%s %s %s', self::label($entry), self::describe($entry), str_replace('_', ' ', $action)),
            $entry,
            ['reason' => $reason, ...$properties],
            $actor,
        );
    }

    /** "senatorial_district", "lga", "ward" or "state". */
    public static function level(Model $entry): string
    {
        return match (true) {
            $entry instanceof SenatorialDistrict => 'senatorial_district',
            $entry instanceof Lga => 'lga',
            $entry instanceof Ward => 'ward',
            $entry instanceof State => 'state',
            default => throw new \InvalidArgumentException('Not a hierarchy entry: '.$entry::class),
        };
    }

    public static function label(Model $entry): string
    {
        return match (self::level($entry)) {
            'senatorial_district' => 'Senatorial district',
            'lga' => 'LGA',
            'ward' => 'Ward',
            default => 'State',
        };
    }

    public static function describe(Model $entry): string
    {
        return sprintf('%s (%s)', (string) $entry->getAttribute('name'), (string) $entry->getAttribute('code'));
    }
}
