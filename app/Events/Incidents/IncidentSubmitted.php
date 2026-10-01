<?php

namespace App\Events\Incidents;

final class IncidentSubmitted extends IncidentBroadcast
{
    public function name(): string
    {
        return 'incident.submitted';
    }
}
