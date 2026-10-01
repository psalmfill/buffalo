<?php

namespace App\Support\Incidents;

use App\Enums\Classification;
use App\Models\Incident;
use App\Models\User;
use App\Support\Classification\ClassificationGate;

/**
 * The classification rule for records attached to an incident (evidence,
 * follow-ups): at least the incident's, and within the user's clearance.
 */
final class ChildClassification
{
    /** Why $level is not allowed, or null. */
    public static function problem(mixed $user, Incident $incident, ?Classification $level, string $what): ?string
    {
        if (! $user instanceof User || $level === null) {
            return null;
        }

        if ($level->rank() < $incident->classificationLevel()->rank()) {
            return "{$what} cannot be classified below its incident ({$incident->classificationLevel()->label()}).";
        }

        if (! app(ClassificationGate::class)->canAssign($user, $level)) {
            return 'You cannot classify a record above your own clearance.';
        }

        return null;
    }
}
