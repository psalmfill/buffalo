<?php

namespace App\Actions\Organizations\SitePhotos;

use App\Models\OrganizationSite;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Organizations\SitePhotos;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Puts a site's photos in a new order (Phase 11D). $order must list every
 * live photo of the site exactly once (ULIDs); the cover still shows first.
 */
final class ReorderSitePhotos
{
    /**
     * @param  list<string>  $order
     */
    public function __invoke(OrganizationSite $site, array $order, User $actor): void
    {
        DB::transaction(function () use ($site, $order, $actor): void {
            SitePhotos::lock($site);
            $live = SitePhotos::live($site);
            $ulids = $live->pluck('ulid')->all();

            if (count($order) !== count($ulids) || array_diff($ulids, $order) !== [] || count(array_unique($order)) !== count($order)) {
                throw ValidationException::withMessages(['order' => 'The photos have changed. Reload the page and try again.']);
            }

            foreach ($order as $index => $ulid) {
                DB::table('organization_site_photos')
                    ->where('organization_site_id', $site->id)
                    ->where('ulid', $ulid)
                    ->update(['sort_order' => $index + 1, 'updated_at' => now(), 'updated_by' => $actor->id]);
            }

            AuditLog::event('site_photos_reordered', 'Photos reordered', $site, ['order' => $order], $actor);
        });
    }
}
