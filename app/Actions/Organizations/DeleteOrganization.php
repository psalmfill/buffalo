<?php

namespace App\Actions\Organizations;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Models\Organization;
use App\Models\User;
use App\Support\Jurisdiction\OrganizationSites;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a directory entry (Phase 4B), with a reason: only when it has no
 * sites anywhere. The refusal reveals nothing more than that it has sites.
 */
final class DeleteOrganization
{
    public const HAS_SITES = 'This organization has sites and cannot be deleted.';

    public function __construct(
        private readonly OrganizationSites $sites,
        private readonly DeleteWithReason $delete,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Organization $organization, string $reason, User $actor): void
    {
        if ($this->sites->anyExist($organization)) {
            throw ValidationException::withMessages(['reason' => self::HAS_SITES]);
        }

        ($this->delete)($organization, 'organization', $reason, $actor);
    }
}
