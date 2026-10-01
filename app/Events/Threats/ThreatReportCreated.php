<?php

namespace App\Events\Threats;

final class ThreatReportCreated extends ThreatReportBroadcast
{
    public function name(): string
    {
        return 'threat.created';
    }
}
