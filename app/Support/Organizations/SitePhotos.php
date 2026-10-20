<?php

namespace App\Support\Organizations;

use App\Models\OrganizationSite;
use App\Models\OrganizationSitePhoto;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared pieces of site photos (Phase 11D): the per-site limit, the lock
 * that serialises changes to a site's photos, and the authorised URLs of
 * a photo's files (never storage URLs).
 */
final class SitePhotos
{
    public static function limit(): int
    {
        return max(1, (int) config('buffalo.organizations.site_photo_limit'));
    }

    public static function limitMessage(): string
    {
        return 'A site may have at most '.self::limit().' photos. Delete one before adding another.';
    }

    /** Locks the site's row until the end of the transaction. */
    public static function lock(OrganizationSite $site): void
    {
        DB::table('organization_sites')->where('id', $site->id)->lockForUpdate()->value('id');
    }

    /**
     * The site's live photos (the limit and the order count every one,
     * whatever the viewer may see).
     *
     * @return Collection<int, object{id: int, ulid: string, is_cover: bool, sort_order: int}>
     */
    public static function live(OrganizationSite $site): Collection
    {
        /** @var Collection<int, object{id: int, ulid: string, is_cover: bool, sort_order: int}> */
        return DB::table('organization_site_photos')
            ->where('organization_site_id', $site->id)
            ->whereNull('deleted_at')
            ->get(['id', 'ulid', 'is_cover', 'sort_order']);
    }

    /**
     * @return array{thumb: string, preview: string, original: string}
     */
    public static function urls(OrganizationSite $site, OrganizationSitePhoto $photo): array
    {
        $parameters = ['site' => $site->ulid, 'photo' => $photo->ulid];

        return [
            'thumb' => route('organization-site-photos.thumb', $parameters, false),
            'preview' => route('organization-site-photos.preview', $parameters, false),
            'original' => route('organization-site-photos.original', $parameters, false),
        ];
    }

    /** The cover's thumbnail URL, when the site has a cover (coverPhoto loaded). */
    public static function coverThumb(OrganizationSite $site): ?string
    {
        if (! $site->relationLoaded('coverPhoto') || $site->coverPhoto === null) {
            return null;
        }

        return route('organization-site-photos.thumb', ['site' => $site->ulid, 'photo' => $site->coverPhoto->ulid], false);
    }
}
