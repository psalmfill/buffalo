<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * Media files (spatie/laravel-medialibrary) on the private `evidence` disk.
 *
 * Adds a `ulid` (used in URLs instead of the numeric id) and drops the
 * package's URL attributes: media is never served by URL, only streamed
 * through authorised controllers (e.g. PersonPhotoController).
 *
 * Soft-deletable (Phase 11D): a replaced or removed organization logo stays
 * in history, and its files stay on disk (the media library removes files
 * only when media is force-deleted). Trashed media never binds in a route.
 *
 * @property string $ulid
 */
class Media extends BaseMedia
{
    use HasUlids;
    use SoftDeletes;

    /** No original_url / preview_url: there are no public URLs. */
    protected $appends = [];

    protected $hidden = ['id', 'model_type', 'model_id', 'disk', 'conversions_disk', 'custom_properties', 'manipulations', 'responsive_images'];

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['ulid'];
    }

    public function getRouteKeyName(): string
    {
        return 'ulid';
    }
}
