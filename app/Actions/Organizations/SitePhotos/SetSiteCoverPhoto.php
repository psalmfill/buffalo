<?php

namespace App\Actions\Organizations\SitePhotos;

use App\Models\OrganizationSitePhoto;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Organizations\SitePhotos;
use Illuminate\Support\Facades\DB;

/**
 * Makes a photo its site's cover (Phase 11D). The previous cover is unset
 * in the same transaction; a partial unique index allows one per site.
 */
final class SetSiteCoverPhoto
{
    public function __invoke(OrganizationSitePhoto $photo, User $actor): void
    {
        if ($photo->is_cover) {
            return;
        }

        DB::transaction(function () use ($photo, $actor): void {
            $site = $photo->site;
            SitePhotos::lock($site);

            DB::table('organization_site_photos')
                ->where('organization_site_id', $site->id)
                ->where('is_cover', true)
                ->update(['is_cover' => false, 'updated_at' => now(), 'updated_by' => $actor->id]);

            $photo->forceFill(['is_cover' => true, 'updated_by' => $actor->id])->save();

            AuditLog::event('site_photo_cover_set', 'Cover photo set', $site, ['photo' => $photo->ulid], $actor);
        });
    }
}
