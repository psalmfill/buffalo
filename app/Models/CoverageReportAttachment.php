<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\EvidenceScanStatus;
use App\Enums\EvidenceType;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Models\Concerns\LogsBuffaloActivity;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Database\Factories\CoverageReportAttachmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * A photo or video attached to a security report (Phase 11G; the table is
 * `coverage_report_attachments`). Stored and served like incident evidence
 * (Phase 5B):
 *
 * - Jurisdiction: its own state_id/lga_id, kept equal to the report's
 *   (access rule 9), AND visible only while its report is (which adds the
 *   report's classification).
 * - Classification: its own, never below the report's (database triggers).
 * - The file: one media item in the `report_attachment` collection on the
 *   private `evidence` disk (so the evidence backups include it), stored
 *   exactly as uploaded; `sha256` is checked on every download. Images get
 *   EXIF-free `thumb` and `preview` conversions. Files are served only by
 *   ReportAttachmentController, never by URL.
 * - Malware: scanned before acceptance; larger files wait `quarantined` and
 *   are never served until scanned clean (Phase 8B.4).
 * - Every view and download is access-logged.
 * - Soft deletes only (with a reason); the stored file is kept.
 *
 * @property int $id
 * @property string $ulid
 * @property int $coverage_report_id
 * @property int $state_id
 * @property int $lga_id
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
 * @property-read CoverageReport|null $report
 * @property-read User $uploader
 */
class CoverageReportAttachment extends Model implements Classified, HasMedia, JurisdictionScoped
{
    use HasClassification;

    /** @use HasFactory<CoverageReportAttachmentFactory> */
    use HasFactory;

    use HasJurisdiction;
    use HasUlidRouteKey;
    use InteractsWithMedia;
    use LogsBuffaloActivity;
    use LogsReads;
    use SoftDeletes;

    public const ORIGINAL = 'report_attachment';

    /** Media custom property: the original has not been scanned yet (Phase 8B.4). */
    public const AWAITING_SCAN = 'awaiting_scan';

    protected $fillable = ['caption', 'captured_at'];

    protected $hidden = ['id', 'coverage_report_id', 'state_id', 'lga_id', 'uploaded_by', 'created_by', 'updated_by', 'captured_location'];

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
        // Visible only while the report is (jurisdiction AND classification).
        static::addGlobalScope('report', fn (Builder $query) => $query->whereHas('report'));
    }

    /**
     * @return array<int, string>
     */
    protected function auditExcept(): array
    {
        // The point is logged as coordinates by UpdateReportAttachment.
        return ['captured_location'];
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
     * @param  Builder<CoverageReportAttachment>  $query
     * @return Builder<CoverageReportAttachment>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('coverage_report_attachments.*');
        }

        return $query->selectRaw('ST_Y(coverage_report_attachments.captured_location::geometry) AS latitude, ST_X(coverage_report_attachments.captured_location::geometry) AS longitude');
    }

    /**
     * @return BelongsTo<CoverageReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(CoverageReport::class, 'coverage_report_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
