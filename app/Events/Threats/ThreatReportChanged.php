<?php

namespace App\Events\Threats;

final class ThreatReportChanged extends ThreatReportBroadcast
{
    public function name(): string
    {
        return 'threat.changed';
    }
}
