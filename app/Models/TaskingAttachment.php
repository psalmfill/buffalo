<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\EvidenceScanStatus;
use App\Enums\EvidenceType;
use App\Enums\TaskingStatus;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use App\Support\Jurisdiction\Contracts\LgaVisibilityRule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * A photo, video or document on a tasking or on an LGA's report back
 * (Phase 11H), stored and served exactly like security report attachments
 * (Phase 11G) and incident evidence (Phase 5B): one unaltered original on
 * the private evidence disk (so the evidence backups include it) with its
 * SHA-256, malware-scanned, EXIF-free image previews, served only by
 * TaskingAttachmentController, every view and download logged.
 *
 * Jurisdiction:
 * - an attachment of the tasking carries the tasking's state only, and an
 *   LGA chief sees it when their LGA is a recipient of the sent tasking;
 * - an attachment of a response carries the recipient's state and LGA, and
 *   only that LGA's chief (and the state's directors, national oversight)
 *   sees it.
 * Both are visible only while their tasking is, and never below its level.
 *
 * @property int $id
 * @property string $ulid
 * @property int $tasking_id
 * @property int|null $tasking_recipient_id
 * @property int $state_id
 * @property int|null $lga_id
 * @property string|null $caption
 * @property EvidenceType $media_type
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property CarbonImmutable|null $captured_at
 * @property array<string, mixed>|null $exif_summary
 * @property Classification $classification
 * @property EvidenceScanStatus $scan_status
 * @property CarbonImmutable|null $scanned_at
 * @property CarbonImmutable|null $processed_at
 * @property int $uploaded_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $deleted_at
 * @property float|null $latitude only with withCoordinates()
 * @property float|null $longitude only with withCoordinates()
 * @property-read Tasking|null $tasking
 * @property-read TaskingRecipient|null $recipient
 * @property-read User $uploader
 */
class TaskingAttachment extends Model implements Classified, HasMedia, JurisdictionScoped, LgaVisibilityRule
{
    use HasClassification;
    use HasJurisdiction;
    use HasUlidRouteKey;
    use InteractsWithMedia;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    public const ORIGINAL = 'tasking_attachment';

    /** Media custom property: the original has not been scanned yet (Phase 8B.4). */
    public const AWAITING_SCAN = 'awaiting_scan';

    protected $fillable = ['caption'];

    protected $hidden = ['id', 'tasking_id', 'tasking_recipient_id', 'state_id', 'lga_id', 'uploaded_by', 'created_by', 'updated_by', 'captured_location'];

    protected $attributes = [
        'classification' => 'restricted',
        'scan_status' => 'quarantined',
    ];

    protected function casts(): array
    {
        return [
            'media_type' => EvidenceType::class,
            'classification' => Classification::class,
            'scan_status' => EvidenceScanStatus::class,
            'exif_summary' => 'array',
            'size_bytes' => 'integer',
            'captured_at' => 'immutable_datetime',
            'scanned_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        // Visible only while the tasking is (jurisdiction AND classification).
        static::addGlobalScope('tasking', fn (Builder $query) => $query->whereHas('tasking'));
    }

    /**
     * @return array<int, string>
     */
    protected function auditExcept(): array
    {
        return ['captured_location'];
    }

    public function applyLgaVisibility(Builder $query, int $lgaId): void
    {
        $query->where($this->qualifyColumn('lga_id'), $lgaId)
            ->orWhere(fn (Builder $own) => $own->whereNull($this->qualifyColumn('lga_id'))
                ->whereExists(fn ($sub) => $sub->selectRaw('1')->from('tasking_recipients')
                    ->join('taskings', 'taskings.id', '=', 'tasking_recipients.tasking_id')
                    ->whereColumn('tasking_recipients.tasking_id', $this->qualifyColumn('tasking_id'))
                    ->where('taskings.status', '<>', TaskingStatus::Draft->value)
                    ->where('tasking_recipients.lga_id', $lgaId)));
    }

    public function visibleToLga(int $lgaId): bool
    {
        if ($this->lga_id !== null) {
            return $this->lga_id === $lgaId;
        }

        return DB::table('tasking_recipients')
            ->join('taskings', 'taskings.id', '=', 'tasking_recipients.tasking_id')
            ->where('tasking_recipients.tasking_id', $this->tasking_id)
            ->where('taskings.status', '<>', TaskingStatus::Draft->value)
            ->where('tasking_recipients.lga_id', $lgaId)
            ->exists();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::ORIGINAL)
            ->useDisk('evidence')
            ->singleFile();
    }

    public function registerMediaConversions(?BaseMedia $media = null): void
    {
        if ($media !== null && ! in_array($media->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return;
        }

        // Never decode a file that has not been scanned clean (Phase 8B.4).
        if ($media?->getCustomProperty(self::AWAITING_SCAN) === true) {
            return;
        }

        $this->addMediaConversion('thumb')
            ->performOnCollections(self::ORIGINAL)
            ->queued()
            ->format('jpg')
            ->fit(Fit::Max, (int) config('buffalo.evidence.thumb_px'), (int) config('buffalo.evidence.thumb_px'));

        $this->addMediaConversion('preview')
            ->performOnCollections(self::ORIGINAL)
            ->queued()
            ->format('jpg')
            ->fit(Fit::Max, (int) config('buffalo.evidence.preview_px'), (int) config('buffalo.evidence.preview_px'));
    }

    /** The stored original. */
    public function original(): ?Media
    {
        /** @var Media|null */
        return $this->getFirstMedia(self::ORIGINAL);
    }

    public function isScannedClean(): bool
    {
        return $this->scan_status === EvidenceScanStatus::Clean;
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * @param  Builder<TaskingAttachment>  $query
     * @return Builder<TaskingAttachment>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('tasking_attachments.*');
        }

        return $query->selectRaw('ST_Y(tasking_attachments.captured_location::geometry) AS latitude, ST_X(tasking_attachments.captured_location::geometry) AS longitude');
    }

    /** On the tasking itself (not a report back). */
    public function isOnTasking(): bool
    {
        return $this->tasking_recipient_id === null;
    }

    /**
     * @return BelongsTo<Tasking, $this>
     */
    public function tasking(): BelongsTo
    {
        return $this->belongsTo(Tasking::class);
    }

    /**
     * @return BelongsTo<TaskingRecipient, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(TaskingRecipient::class, 'tasking_recipient_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
