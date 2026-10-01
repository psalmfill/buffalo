<?php

namespace App\Models;

use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Models\Concerns\HasClassification;
use App\Models\Concerns\LogsReads;
use App\Support\Classification\Contracts\Classified;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * A piece of incident evidence (Phase 5B): an image, document, video or
 * audio file with its metadata.
 *
 * - Jurisdiction: its own state_id/lga_id, kept equal to the incident's
 *   (IncidentChild), and visible only while the incident is.
 * - Classification: its own, never below the incident's (database
 *   triggers); items above the viewer's clearance are simply absent.
 * - The file: one media item in the `original` collection on the private
 *   `evidence` disk, stored exactly as uploaded; `sha256` is its hash at
 *   upload, checked on every download. Images get `thumb` and `preview`
 *   conversions (re-encoded by GD, so without EXIF). Files are only ever
 *   streamed by EvidenceFileController, never by URL.
 * - Every view and download is access-logged (any classification).
 * - Soft deletes only; the stored file is kept (medialibrary removes files
 *   only on force delete, which the application never does).
 *
 * @property string $title
 * @property string|null $description
 * @property EvidenceType $evidence_type
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property CarbonImmutable|null $captured_at
 * @property array<string, mixed>|null $exif_summary
 * @property Classification $classification
 * @property CarbonImmutable|null $processed_at
 * @property int $uploaded_by
 * @property CarbonImmutable|null $deleted_at
 * @property float|null $latitude
 * @property float|null $longitude
 * @property-read User $uploader
 */
class IncidentEvidence extends IncidentChild implements Classified, HasMedia
{
    use HasClassification;
    use InteractsWithMedia;
    use LogsReads;

    public const ORIGINAL = 'original';

    protected $table = 'incident_evidence';

    protected $fillable = ['title', 'description', 'captured_at'];

    protected $hidden = ['id', 'incident_id', 'state_id', 'lga_id', 'uploaded_by', 'created_by', 'updated_by', 'captured_location'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'evidence_type' => EvidenceType::class,
            'classification' => Classification::class,
            'exif_summary' => 'array',
            'size_bytes' => 'integer',
            'captured_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function auditExcept(): array
    {
        // The point is logged as coordinates by UpdateEvidence.
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
        // Images only; PDF, video and audio previews would need Imagick,
        // Ghostscript or ffmpeg, which the stack does not include.
        if ($media !== null && ! in_array($media->mime_type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
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

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }

    /**
     * @param  Builder<IncidentEvidence>  $query
     * @return Builder<IncidentEvidence>
     */
    public function scopeWithCoordinates(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('incident_evidence.*');
        }

        return $query->selectRaw('ST_Y(incident_evidence.captured_location::geometry) AS latitude, ST_X(incident_evidence.captured_location::geometry) AS longitude');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
