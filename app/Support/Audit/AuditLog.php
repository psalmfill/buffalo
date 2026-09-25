<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Named audit events (beyond automatic model changes), e.g. a role change
 * or a two-factor reset. The acting user is recorded as the causer.
 * Never pass secrets (passwords, tokens, links) in $properties; excluded
 * keys are removed anyway (BuffaloLogActivityAction).
 */
final class AuditLog
{
    public const LOG_NAME = 'audit';

    /**
     * @param  array<string, mixed>  $properties
     */
    public static function event(string $event, string $description, ?Model $subject = null, array $properties = [], ?Model $causer = null): void
    {
        $logger = activity(self::LOG_NAME)->event($event)->withProperties($properties);

        if ($subject !== null) {
            $logger->performedOn($subject);
        }

        if ($causer !== null) {
            $logger->causedBy($causer);
        }

        $logger->log($description);
    }
}
