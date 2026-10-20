<?php

namespace App\Actions\Organizations\SitePhotos;

use App\Models\OrganizationSitePhoto;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Organizations\SitePhotos;
use Illuminate\Support\Facades\DB;

/**
 * Deletes a site photo with a reason (Phase 11D). The row is soft-deleted
 * and its file kept; it can no longer be listed or fetched. When it was the
 * cover, the first remaining photo becomes the cover.
 */
final class DeleteSitePhoto
{
    public function __invoke(OrganizationSitePhoto $photo, string $reason, User $actor): void
    {
        DB::transaction(function () use ($photo, $reason, $actor): void {
            $site = $photo->site;
            SitePhotos::lock($site);
            $wasCover = $photo->is_cover;

            $photo->forceFill(['is_cover' => false, 'updated_by' => $actor->id])->save();
            $photo->delete();

            $next = $wasCover ? SitePhotos::live($site)->sortBy([['sort_order', 'asc'], ['id', 'asc']])->first() : null;

            if ($next !== null) {
                DB::table('organization_site_photos')->where('id', $next->id)->update(['is_cover' => true, 'updated_at' => now()]);
            }

            AuditLog::event('site_photo_deleted', 'Photo deleted', $site, [
                'photo' => $photo->ulid,
                'reason' => $reason,
                'new_cover' => $next?->ulid,
            ], $actor);
        });
    }
}
