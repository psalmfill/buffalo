<?php

namespace App\Support\Organizations;

use App\Models\Organization;

/**
 * Authorised URLs of an organization's logo (Phase 11D). The media ULID is
 * in the URL, so a new logo has a new URL and long caching is safe.
 */
final class OrganizationLogos
{
    /**
     * The current logo's URLs, or null (no logo, or `logo` not loaded).
     *
     * @return array{thumb: string, full: string}|null
     */
    public static function urls(Organization $organization): ?array
    {
        if (! $organization->relationLoaded('logo') || $organization->logo === null) {
            return null;
        }

        $parameters = ['organization' => $organization->ulid, 'media' => $organization->logo->ulid];

        return [
            'thumb' => route('organizations.logo.show', [...$parameters, 'conversion' => 'thumb'], false),
            'full' => route('organizations.logo.show', $parameters, false),
        ];
    }
}
