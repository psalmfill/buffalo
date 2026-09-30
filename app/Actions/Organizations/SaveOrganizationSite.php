<?php

namespace App\Actions\Organizations;

use App\Models\Lga;
use App\Models\Organization;
use App\Models\OrganizationSite;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates an organization site (Phase 4B). The organization and
 * LGA are fixed once created. Location changes are audited as a named event
 * with coordinates.
 */
final class SaveOrganizationSite
{
    /**
     * @param  array<string, mixed>  $data  OrganizationSiteRequest::values()
     */
    public function create(Organization $organization, Lga $lga, array $data, User $actor): OrganizationSite
    {
        return DB::transaction(function () use ($organization, $lga, $data, $actor): OrganizationSite {
            $site = new OrganizationSite;
            $site->forceFill([
                ...self::attributes($data),
                'organization_id' => $organization->id,
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->logLocation($site, null, self::coordinates($data), $actor);

            return $site;
        });
    }

    /**
     * @param  array<string, mixed>  $data  OrganizationSiteRequest::values()
     */
    public function update(OrganizationSite $site, array $data, User $actor): void
    {
        DB::transaction(function () use ($site, $data, $actor): void {
            $row = OrganizationSite::query()->withCoordinates()->whereKey($site->id)->first();
            $before = $row?->latitude === null ? null : ['latitude' => round($row->latitude, 6), 'longitude' => round((float) $row->longitude, 6)];
            $after = self::coordinates($data);
            $attributes = self::attributes($data);

            if ($before === $after) {
                unset($attributes['location']);
            }

            $site->forceFill($attributes);

            if ($site->isDirty()) {
                $site->forceFill(['updated_by' => $actor->id])->save();
            }

            $this->logLocation($site, $before, $after, $actor);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        $coordinates = self::coordinates($data);
        unset($data['latitude'], $data['longitude']);

        return [
            ...$data,
            'location' => OrganizationSite::pointFrom($coordinates['latitude'] ?? null, $coordinates['longitude'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{latitude: float, longitude: float}|null
     */
    private static function coordinates(array $data): ?array
    {
        return isset($data['latitude'], $data['longitude'])
            ? ['latitude' => round((float) $data['latitude'], 6), 'longitude' => round((float) $data['longitude'], 6)]
            : null;
    }

    /**
     * @param  array{latitude: float, longitude: float}|null  $before
     * @param  array{latitude: float, longitude: float}|null  $after
     */
    private function logLocation(OrganizationSite $site, ?array $before, ?array $after, User $actor): void
    {
        if ($before !== $after) {
            AuditLog::event('location_changed', "Location of site {$site->name} changed", $site, ['old' => $before, 'new' => $after], $actor);
        }
    }
}
