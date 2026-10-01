<?php

namespace App\Events\Incidents;

final class IncidentEscalated extends IncidentBroadcast
{
    public function name(): string
    {
        return 'incident.escalated';
    }
}
