<?php

namespace App\Actions\Evidence;

use App\Enums\Classification;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\User;

/**
 * Before an incident's classification is raised, raises every evidence item
 * still below the new level to match (Phase 5B): evidence is never
 * classified below its incident. Each change is logged
 * (`classification_changed`). Call inside the transaction that raises the
 * incident, before saving it; the database refuses the incident otherwise.
 *
 * Whoever may raise the incident to $level can see every level below it,
 * and the items share the incident's place, so the scoped query reaches
 * them all (removed items included).
 */
final class RaiseEvidenceClassification
{
    public function __invoke(Incident $incident, Classification $level, User $actor): int
    {
        $lower = array_map(
            fn (Classification $c) => $c->value,
            array_filter(Classification::cases(), fn (Classification $c) => $c->rank() < $level->rank()),
        );

        if ($lower === []) {
            return 0;
        }

        $items = IncidentEvidence::withTrashed()
            ->where('incident_id', $incident->id)
            ->whereIn('classification', $lower)
            ->get();

        foreach ($items as $item) {
            $item->forceFill(['classification' => $level, 'updated_by' => $actor->id])->save();
        }

        return $items->count();
    }
}
