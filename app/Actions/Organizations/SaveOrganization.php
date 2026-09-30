<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a directory entry (Phase 4B).
 */
final class SaveOrganization
{
    /**
     * @param  array<string, mixed>  $data  OrganizationRequest::values()
     */
    public function create(array $data, User $actor): Organization
    {
        return DB::transaction(function () use ($data, $actor): Organization {
            $organization = new Organization;
            $organization->forceFill([...$data, 'created_by' => $actor->id, 'updated_by' => $actor->id])->save();

            return $organization;
        });
    }

    /**
     * @param  array<string, mixed>  $data  OrganizationRequest::values()
     */
    public function update(Organization $organization, array $data, User $actor): void
    {
        DB::transaction(function () use ($organization, $data, $actor): void {
            $organization->forceFill($data);

            if ($organization->isDirty()) {
                $organization->forceFill(['updated_by' => $actor->id])->save();
            }
        });
    }
}
