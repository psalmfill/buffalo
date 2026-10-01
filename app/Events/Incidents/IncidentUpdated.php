<?php

namespace App\Events\Incidents;

final class IncidentUpdated extends IncidentBroadcast
{
    public function name(): string
    {
        return 'incident.updated';
    }
}
