<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Application settings changed in the UI (Phase 11A.3): one row per key
 * with a validated JSON document (today only `ui_theme`). Changes are
 * audit-logged by the code that makes them, as named events.
 *
 * @property int $id
 * @property string $key
 * @property array<string, mixed> $value
 * @property int|null $updated_by
 */
class AppSetting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by'];

    protected $hidden = ['id', 'updated_by'];

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function value(string $key): ?array
    {
        $value = self::query()->where('key', $key)->value('value');

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    public static function put(string $key, array $value, User $by): void
    {
        self::query()->updateOrCreate(['key' => $key], ['value' => $value, 'updated_by' => $by->id]);
    }

    public static function forget(string $key): void
    {
        self::query()->where('key', $key)->delete();
    }
}
