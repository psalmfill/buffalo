<?php

namespace App\Http\Controllers\Incidents;

use App\Enums\MatchContext;
use App\Http\Controllers\Persons\PersonEntryController;
use App\Models\Incident;

/**
 * People entered from the incident form (Phase 5A): match context
 * `incident_entry`; persons the user may record need `linkToIncident`.
 */
class IncidentPersonController extends PersonEntryController
{
    protected function context(): MatchContext
    {
        return MatchContext::IncidentEntry;
    }

    protected function linkAbility(): string
    {
        return 'linkToIncident';
    }

    protected function authorizeEntry(): void
    {
        $this->authorize('create', Incident::class);
    }

    protected function createdFrom(): string
    {
        return 'an incident';
    }
}
