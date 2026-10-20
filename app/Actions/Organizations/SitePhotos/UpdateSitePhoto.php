<?php

namespace App\Actions\Organizations\SitePhotos;

use App\Models\OrganizationSitePhoto;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Carbon\CarbonImmutable;

/**
 * Changes a site photo's caption and date (Phase 11D); a named event on
 * the site with the old and new values.
 */
final class UpdateSitePhoto
{
    public function __invoke(OrganizationSitePhoto $photo, ?string $caption, ?CarbonImmutable $takenOn, User $actor): void
    {
        $old = ['caption' => $photo->caption, 'taken_on' => $photo->taken_on?->toDateString()];

        $photo->fill(['caption' => $caption, 'taken_on' => $takenOn])->forceFill(['updated_by' => $actor->id]);

        if (! $photo->isDirty(['caption', 'taken_on'])) {
            return;
        }

        $photo->save();

        AuditLog::event('site_photo_updated', 'Photo details changed', $photo->site, [
            'photo' => $photo->ulid,
            'old' => $old,
            'attributes' => ['caption' => $photo->caption, 'taken_on' => $photo->taken_on?->toDateString()],
        ], $actor);
    }
}
