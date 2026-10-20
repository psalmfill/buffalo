<?php

namespace App\Actions\Organizations\SitePhotos;

use App\Models\OrganizationSite;
use App\Models\OrganizationSitePhoto;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Organizations\SitePhotos;
use App\Support\Persons\PhotoSanitiser;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a photo to a site (Phase 11D). Only a re-encoded copy without
 * metadata (EXIF and GPS included), scaled to at most 2,400 px on the long
 * side, is stored, under a neutral file name. The photo takes the site's
 * place; the site's row is locked so the photo limit and the order hold
 * under concurrent uploads. A site's first photo becomes its cover.
 */
final class AddSitePhoto
{
    public function __construct(private readonly PhotoSanitiser $sanitiser) {}

    public function __invoke(OrganizationSite $site, UploadedFile $upload, ?string $caption, ?CarbonImmutable $takenOn, User $actor): OrganizationSitePhoto
    {
        $size = (int) config('buffalo.organizations.site_photo_max_dimension');
        $clean = $this->sanitiser->sanitise((string) $upload->getRealPath(), $size, $size);

        try {
            return DB::transaction(function () use ($site, $clean, $caption, $takenOn, $actor): OrganizationSitePhoto {
                SitePhotos::lock($site);
                $live = SitePhotos::live($site);

                if ($live->count() >= SitePhotos::limit()) {
                    throw ValidationException::withMessages(['photo' => SitePhotos::limitMessage()]);
                }

                $photo = (new OrganizationSitePhoto(['caption' => $caption, 'taken_on' => $takenOn]))->forceFill([
                    'organization_site_id' => $site->id,
                    'state_id' => $site->state_id,
                    'lga_id' => $site->lga_id,
                    'is_cover' => ! $live->contains('is_cover', true),
                    'sort_order' => ((int) $live->max('sort_order')) + 1,
                    'uploaded_by' => $actor->id,
                ]);
                $photo->save();

                $photo->addMedia($clean['path'])
                    ->usingName('photo')
                    ->usingFileName('photo.'.$clean['extension'])
                    ->toMediaCollection(OrganizationSitePhoto::PHOTO);

                AuditLog::event('site_photo_added', 'Photo added', $site, [
                    'photo' => $photo->ulid,
                    'caption' => $photo->caption,
                    'cover' => $photo->is_cover,
                ], $actor);

                return $photo;
            });
        } finally {
            if (is_file($clean['path'])) {
                @unlink($clean['path']);
            }
        }
    }
}
