<?php

namespace App\Enums;

/**
 * Where a person match happened (record_match_events.context).
 */
enum MatchContext: string
{
    case PersonSearch = 'person_search';
    case PersonCreate = 'person_create';
    case IncidentEntry = 'incident_entry';
    // Phase 11C: persons entered on an arrest.
    case ArrestEntry = 'arrest_entry';

    public function label(): string
    {
        return match ($this) {
            self::PersonSearch => 'Person search',
            self::PersonCreate => 'New person record',
            self::IncidentEntry => 'Incident entry',
            self::ArrestEntry => 'Arrest entry',
        };
    }
}
