<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;

/**
 * Media files (spatie/laravel-medialibrary) on the private `evidence` disk.
 *
 * Adds a `ulid` (used in URLs instead of the numeric id) and drops the
 * package's URL attributes: media is never served by URL, only streamed
 * through authorised controllers (e.g. PersonPhotoController).
 *
 * @property string $ulid
 */
class Media extends BaseMedia
{
    use HasUlids;

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
