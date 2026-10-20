<?php

namespace App\Actions\Organizations;

use App\Models\Media;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Persons\PhotoSanitiser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Sets an organization's logo (Phase 11D). Only a re-encoded copy without
 * metadata, scaled to fit 512 × 512 px, is stored (PhotoSanitiser), under a
 * neutral file name. The previous logo is soft-deleted: kept in history,
 * never shown. The new media's ULID is in the logo URL, so browsers that
 * cached the old one fetch the new one.
 */
final class AddOrganizationLogo
{
    public function __construct(private readonly PhotoSanitiser $sanitiser) {}

    public function __invoke(Organization $organization, UploadedFile $upload, User $actor): Media
    {
        $size = (int) config('buffalo.organizations.logo_max_dimension');
        $clean = $this->sanitiser->sanitise((string) $upload->getRealPath(), $size, $size);

        try {
            return DB::transaction(function () use ($organization, $clean, $actor): Media {
                $previous = $organization->logo()->get();

                /** @var Media $media */
                $media = $organization->addMedia($clean['path'])
                    ->usingName('logo')
                    ->usingFileName('logo.'.$clean['extension'])
                    ->withCustomProperties(['uploaded_by' => $actor->ulid])
                    ->toMediaCollection(Organization::LOGO);

                $previous->each(fn (Media $old) => $old->delete());

                AuditLog::event('logo_added', $previous->isEmpty() ? 'Logo added' : 'Logo replaced', $organization, [
                    'logo' => $media->ulid,
                    'replaced' => $previous->pluck('ulid')->values()->all(),
                ], $actor);

                return $media;
            });
        } finally {
            if (is_file($clean['path'])) {
                @unlink($clean['path']);
            }
        }
    }
}
