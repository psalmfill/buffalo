<?php

namespace Tests\Support\Incidents;

use App\Enums\IncidentStatus;
use App\Enums\Severity;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\CrossJurisdiction\CrossFixtures;

/**
 * Helpers for the incident workflow tests (Phase 5C).
 */
final class WorkflowFixtures
{
    /** A draft with the core facts, created by $creator. */
    public static function draft(Lga $lga, User $creator, array $attributes = []): Incident
    {
        CrossFixtures::system();

        return Incident::factory()->in($lga)->by($creator)->create([
            'description' => 'Armed men abducted travellers near the toll gate at dusk.',
            'severity' => Severity::High,
            ...$attributes,
        ]);
    }

    /** POST a workflow step as $user. */
    public static function step(User $user, Incident $incident, string $step, array $data = []): TestResponse
    {
        return CrossFixtures::signIn($user)->post("/incidents/{$incident->ulid}/{$step}", $data);
    }

    public static function submit(User $user, Incident $incident): TestResponse
    {
        return self::step($user, $incident, 'submit', ['confirm_duplicates' => true]);
    }

    /** Forces a status (and its workflow columns) without the workflow, for set-up. */
    public static function at(Incident $incident, IncidentStatus $status, array $columns = []): Incident
    {
        CrossFixtures::system();
        $reference = $incident->reference_no ?? 'BUF/T/TST/2026/'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('incidents')->where('id', $incident->id)->update([
            'status' => $status->value,
            'reference_no' => $status === IncidentStatus::Draft ? null : $reference,
            'submitted_at' => $status === IncidentStatus::Draft ? null : now(),
            ...$columns,
        ]);

        return $incident->fresh() ?? $incident;
    }

    public static function fresh(Incident $incident): Incident
    {
        CrossFixtures::system();

        return Incident::query()->findOrFail($incident->id);
    }
}
