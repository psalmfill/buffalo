<?php

namespace App\Models;

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Enums\SchedulePauseReason;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's recurring report (Phase 7D): SITREP, weekly or monthly summary,
 * daily / weekly (weekday 1 = Monday) / monthly (day 1–28) at a time of day,
 * all Africa/Lagos. Generated as its owner with their scopes at the time.
 *
 * @property int $id
 * @property string $ulid
 * @property int $user_id
 * @property ReportType $report_type
 * @property array<string, mixed> $parameters
 * @property ReportFormat $format
 * @property string $cadence
 * @property string $time_of_day
 * @property int|null $weekday
 * @property int|null $day_of_month
 * @property bool $is_active
 * @property SchedulePauseReason|null $paused_reason
 * @property CarbonImmutable|null $paused_at
 * @property CarbonImmutable|null $last_run_at
 * @property CarbonImmutable $next_run_at
 * @property-read User $owner
 */
class ReportSchedule extends Model
{
    use HasUlidRouteKey;
    use LogsBuffaloActivity;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'user_id'];

    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'format' => ReportFormat::class,
            'parameters' => 'array',
            'is_active' => 'boolean',
            'paused_reason' => SchedulePauseReason::class,
            'paused_at' => 'immutable_datetime',
            'weekday' => 'integer',
            'day_of_month' => 'integer',
            'last_run_at' => 'immutable_datetime',
            'next_run_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<ReportSchedule>  $query
     * @return Builder<ReportSchedule>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('report_schedules.user_id', $user->id);
    }

    /** The next run strictly after $after (Africa/Lagos wall-clock times). */
    public function nextRunAfter(CarbonImmutable $after): CarbonImmutable
    {
        return self::next($this->cadence, $this->time_of_day, $this->weekday, $this->day_of_month, $after);
    }

    public static function next(string $cadence, string $time, ?int $weekday, ?int $day, CarbonImmutable $after): CarbonImmutable
    {
        $tz = (string) config('app.timezone');
        $local = $after->setTimezone($tz);
        [$hour, $minute] = array_map('intval', explode(':', $time));
        $candidate = $local->setTime($hour, $minute);

        $candidate = match ($cadence) {
            'weekly' => $candidate->addDays((($weekday ?? 1) - $candidate->dayOfWeekIso + 7) % 7),
            'monthly' => $candidate->setDay($day ?? 1),
            default => $candidate,
        };

        while ($candidate->lessThanOrEqualTo($local)) {
            $candidate = match ($cadence) {
                'weekly' => $candidate->addWeek(),
                'monthly' => $candidate->addMonthNoOverflow()->setDay($day ?? 1),
                default => $candidate->addDay(),
            };
        }

        return $candidate;
    }

    public function describe(): string
    {
        return match ($this->cadence) {
            'weekly' => 'Every '.CarbonImmutable::create(2026, 1, 4 + ($this->weekday ?? 1))?->format('l').' at '.$this->time_of_day,
            'monthly' => 'Monthly on day '.$this->day_of_month.' at '.$this->time_of_day,
            default => 'Daily at '.$this->time_of_day,
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
