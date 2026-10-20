<?php

namespace App\Models;

use App\Models\Concerns\HasJurisdiction;
use App\Models\Concerns\HasUlidRouteKey;
use App\Support\Jurisdiction\Contracts\JurisdictionScoped;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * A photo of an organization site (Phase 11D). Carries its own state_id and
 * lga_id (kept equal to the site's by a cascading composite FK) and is
 * scoped on them (access rule 9), and is visible only while its site is: it
 * has the site's classification, never one of its own.
 *
 * The file is media ("photo") on the private evidence disk, re-encoded
 * without metadata and scaled to at most 2,400 px (AddSitePhoto); thumb
 * and preview conversions are generated on the queue. Served only by
 * SitePhotoController. Changes are named events on the site
 * (SitePhotos), so they appear in the site's history.
 *
 * @property int $id
 * @property string $ulid
 * @property int $organization_site_id
 * @property int $state_id
 * @property int $lga_id
 * @property string|null $caption
 * @property CarbonImmutable|null $taken_on
 * @property bool $is_cover
 * @property int $sort_order
 * @property int|null $uploaded_by
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read OrganizationSite $site
 * @property-read Media|null $file
 * @property-read User|null $uploader
 */
class OrganizationSitePhoto extends Model implements HasMedia, JurisdictionScoped
{
    use HasJurisdiction;
    use HasUlidRouteKey;
    use InteractsWithMedia;
    use SoftDeletes;

    public const PHOTO = 'photo';

    protected $fillable = ['caption', 'taken_on'];

    protected $hidden = ['id', 'organization_site_id', 'state_id', 'lga_id', 'uploaded_by', 'updated_by'];

    protected $attributes = [
        'is_cover' => false,
        'sort_order' => 0,
    ];

    protected function casts(): array
    {
        return [
            'taken_on' => 'immutable_date',
            'is_cover' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Only while the site is visible (which adds the site's
        // classification); the photo's own jurisdiction applies as well.
        static::addGlobalScope('site', fn (Builder $query) => $query->whereHas('site'));
    }

    /**
     * @return BelongsTo<OrganizationSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(OrganizationSite::class, 'organization_site_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The photo's file.
     *
     * @return MorphOne<Media, $this>
     */
    public function file(): MorphOne
    {
        return $this->morphOne(Media::class, 'model')->where('collection_name', self::PHOTO);
    }

    /**
     * Cover first, then by sort order (then upload order).
     *
     * @param  Builder<OrganizationSitePhoto>  $query
     * @return Builder<OrganizationSitePhoto>
     */
    public function scopeGalleryOrder(Builder $query): Builder
    {
        return $query->orderByDesc('organization_site_photos.is_cover')
            ->orderBy('organization_site_photos.sort_order')
            ->orderBy('organization_site_photos.id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::PHOTO)
            ->useDisk('evidence')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp']);
    }

    public function registerMediaConversions(?BaseMedia $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->performOnCollections(self::PHOTO)
            ->queued()
            ->keepOriginalImageFormat()
            ->fit(Fit::Max, 300, 300);

        $this->addMediaConversion('preview')
            ->performOnCollections(self::PHOTO)
            ->queued()
            ->keepOriginalImageFormat()
            ->fit(Fit::Max, 1200, 1200);
    }
}
