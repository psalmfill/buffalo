<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\BlackspotSuggestion;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionGate;

/**
 * Suggested blackspots (Phase 6C): blackspots.suggestions (DG, DDG, SD,
 * DSD) and the suggestion's own jurisdiction. Accepting also needs
 * flashpoints.manage (checked where the flashpoint is created); refreshing
 * needs blackspots.refresh (DG, DDG, SD).
 */
final class BlackspotSuggestionPolicy
{
    public function __construct(private readonly JurisdictionGate $jurisdiction) {}

    public function viewAny(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::BlackspotsSuggestions->value);
    }

    public function view(User $actor, BlackspotSuggestion $suggestion): bool
    {
        return $this->viewAny($actor) && $this->jurisdiction->canAccessOwn($actor, $suggestion);
    }

    /** Accept or dismiss an open suggestion. */
    public function decide(User $actor, BlackspotSuggestion $suggestion): bool
    {
        return $this->view($actor, $suggestion) && $suggestion->status === 'open';
    }

    public function refresh(User $actor): bool
    {
        return $actor->checkPermissionTo(Permission::BlackspotsRefresh->value);
    }
}
