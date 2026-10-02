<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\ReportFormat;
use App\Enums\ReportRunStatus;
use App\Enums\ReportType;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsReads;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One report generation (Phase 7D). Built on the queue through the
 * requester's scopes at generation time; only the requester may download
 * the file (ReportPolicy), and each download is logged in
 * record_access_logs (action export, context the report type). The file
 * expires; the record stays.
 *
 * Not jurisdiction-scoped: a run belongs to its requester. Reads go
 * through scopeOwnedBy() and the policy.
 *
 * @property int $id
 * @property string $ulid
 * @property ReportType $report_type
 * @property ReportFormat $format
 * @property array<string, mixed> $parameters
 * @property int $requested_by
 * @property int|null $report_schedule_id
 * @property ReportRunStatus $status
 * @property Classification $classification
 * @property string|null $file_path
 * @property string|null $sha256
 * @property int|null $size_bytes
 * @property int|null $row_count
 * @property int|null $page_count
 * @property string|null $error_message
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable $created_at
 * @property-read User $requester
 * @property-read ReportSchedule|null $schedule
 */
class ReportRun extends Model
{
    use HasUlidRouteKey;
    use LogsReads;

    protected $guarded = ['*'];

    protected $hidden = ['id', 'requested_by', 'report_schedule_id', 'file_path'];

    protected $attributes = [
        'status' => 'queued',
        'classification' => 'restricted',
    ];

    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'format' => ReportFormat::class,
            'parameters' => 'array',
            'status' => ReportRunStatus::class,
            'classification' => Classification::class,
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @param  Builder<ReportRun>  $query
     * @return Builder<ReportRun>
     */
    public function scopeOwnedBy(Builder $query, User $user): Builder
    {
        return $query->where('report_runs.requested_by', $user->id);
    }

    /** "RPT-<ULID>": printed on every page and in every file. */
    public function reference(): string
    {
        return 'RPT-'.mb_strtoupper($this->ulid);
    }

    public function fileName(): string
    {
        return 'buffalo-'.str_replace('_', '-', $this->report_type->value).'-'.mb_strtolower($this->ulid).'.'.$this->format->value;
    }

    public function isDownloadable(): bool
    {
        return $this->status === ReportRunStatus::Completed
            && $this->file_path !== null
            && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<ReportSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(ReportSchedule::class, 'report_schedule_id');
    }
}
