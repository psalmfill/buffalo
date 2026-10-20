<?php

namespace App\Actions\Organizations;

use App\Models\Media;
use App\Models\Organization;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Removes an organization's logo (Phase 11D): the media is soft-deleted and
 * its files kept, so the logo stays in history.
 */
final class RemoveOrganizationLogo
{
    public function __invoke(Organization $organization, User $actor): void
    {
        DB::transaction(function () use ($organization, $actor): void {
            $logos = $organization->logo()->get();

            if ($logos->isEmpty()) {
                return;
            }

            $logos->each(fn (Media $logo) => $logo->delete());

            AuditLog::event('logo_removed', 'Logo removed', $organization, ['logo' => $logos->pluck('ulid')->values()->all()], $actor);
        });
    }
}
