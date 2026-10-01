<?php

namespace App\Events\Incidents;

final class IncidentStatusChanged extends IncidentBroadcast
{
    public function name(): string
    {
        return 'incident.status-changed';
    }
}
