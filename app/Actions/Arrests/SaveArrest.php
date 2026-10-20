<?php

namespace App\Actions\Arrests;

use App\Enums\ArrestStatus;
use App\Models\Arrest;
use App\Models\ArrestPerson;
use App\Models\ArrestPersonOffence;
use App\Models\ArrestStatusHistory;
use App\Models\Flashpoint;
use App\Models\Lga;
use App\Models\User;
use App\Support\Arrests\ArrestNotifier;
use App\Support\Arrests\ArrestReferenceAllocator;
use App\Support\Arrests\ArrestStatuses;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Records or edits an arrest (Phase 11C), with its arrested persons and
 * their offences, in one transaction.
 *
 * - Creation allocates the reference (gap-free) and writes the first status
 *   history row; each person starts at their own status or the arrest's,
 *   and the arrest shows the most common (ArrestStatuses::mostCommon).
 * - Persons: rows with an id are updated (their person kept when not sent),
 *   new rows created, rows left out soft-deleted; offences likewise per
 *   person. Statuses of existing rows change only through
 *   ChangeArrestStatus (with a comment).
 * - Children follow the arrest's place through cascading composite FKs.
 * - Notifications after a new arrest (ArrestNotifier).
 */
final class SaveArrest
{
    public function __construct(
        private readonly ArrestReferenceAllocator $references,
        private readonly ArrestNotifier $notifier,
    ) {}

    /**
     * @param  array<string, mixed>  $data  ArrestRequest::values()
     */
    public function create(Lga $lga, array $data, User $actor): Arrest
    {
        $arrest = DB::transaction(function () use ($lga, $data, $actor): Arrest {
            $now = CarbonImmutable::now();
            $default = ArrestStatus::from((string) $data['status']);

            $arrest = new Arrest;
            $arrest->forceFill([
                ...self::attributes($data),
                'reference_no' => $this->references->allocate($lga->state_id, $now),
                'lga_id' => $lga->id,
                'state_id' => $lga->state_id,
                'status' => $default,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->syncPersons($arrest, $data['persons'], $default, $actor);
            $status = ArrestStatuses::mostCommon($arrest, $default);
            if ($status !== $arrest->status) {
                $arrest->forceFill(['status' => $status])->saveQuietly();
            }

            (new ArrestStatusHistory)->forceFill([
                'arrest_id' => $arrest->id,
                'state_id' => $arrest->state_id,
                'lga_id' => $arrest->lga_id,
                'from_status' => null,
                'to_status' => $status,
                'comment' => 'Arrest recorded.',
                'changed_by' => $actor->id,
                'changed_at' => $now,
            ])->save();

            return $arrest;
        });

        $this->notifier->created($arrest, $actor);

        return $arrest;
    }

    /**
     * @param  array<string, mixed>  $data  ArrestRequest::values()
     */
    public function update(Arrest $arrest, ?Lga $lga, array $data, User $actor): Arrest
    {
        return DB::transaction(function () use ($arrest, $lga, $data, $actor): Arrest {
            $arrest->forceFill([
                ...self::attributes($data),
                ...($lga !== null ? ['lga_id' => $lga->id, 'state_id' => $lga->state_id] : []),
                'updated_by' => $actor->id,
            ])->save();

            $this->syncPersons($arrest->fresh() ?? $arrest, $data['persons'], $arrest->status, $actor);

            return $arrest;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function attributes(array $data): array
    {
        return [
            'arrested_at' => $data['arrested_at'],
            'arrested_at_precision' => $data['arrested_at_precision'],
            'security_agency_id' => $data['security_agency_id'],
            'agency_formation_id' => $data['agency_formation_id'],
            'community_id' => $data['community_id'],
            'location' => isset($data['latitude'], $data['longitude'])
                ? Flashpoint::pointFrom((float) $data['latitude'], (float) $data['longitude'])
                : null,
            'location_description' => $data['location_description'],
            'incident_id' => $data['incident_id'],
            'notes' => $data['notes'],
            'classification' => $data['classification'],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function syncPersons(Arrest $arrest, array $rows, ArrestStatus $default, User $actor): void
    {
        $keep = [];

        foreach ($rows as $row) {
            $person = $row['id'] !== null
                ? ArrestPerson::query()->where('arrest_id', $arrest->id)->whereKey($row['id'])->firstOrFail()
                : new ArrestPerson;

            $values = [
                'unidentified_description' => $row['unidentified_description'],
                'notes' => $row['notes'],
                'updated_by' => $actor->id,
            ];

            if (array_key_exists('person_id', $row)) {
                $values['person_id'] = $row['person_id'];
            }

            if (! $person->exists) {
                $values += [
                    'arrest_id' => $arrest->id,
                    'state_id' => $arrest->state_id,
                    'lga_id' => $arrest->lga_id,
                    'status' => $row['status'] !== null ? ArrestStatus::from((string) $row['status']) : $default,
                    'created_by' => $actor->id,
                ];
            }

            // An identified person needs no description.
            if (($values['person_id'] ?? $person->person_id) !== null) {
                $values['unidentified_description'] = null;
            }

            $person->forceFill($values)->save();
            $keep[] = $person->id;

            $this->syncOffences($person, $row['offences'], $actor);
        }

        ArrestPerson::query()->where('arrest_id', $arrest->id)->whereNotIn('id', $keep)->get()
            ->each(fn (ArrestPerson $removed) => $removed->delete());
    }

    /**
     * @param  list<array{offence_type_id: int, details: string|null}>  $offences
     */
    private function syncOffences(ArrestPerson $person, array $offences, User $actor): void
    {
        $current = ArrestPersonOffence::query()->where('arrest_person_id', $person->id)->get()->keyBy('offence_type_id');
        $wanted = [];

        foreach ($offences as $offence) {
            $wanted[] = $offence['offence_type_id'];
            $row = $current->get($offence['offence_type_id']) ?? (new ArrestPersonOffence)->forceFill([
                'arrest_person_id' => $person->id,
                'state_id' => $person->state_id,
                'lga_id' => $person->lga_id,
                'offence_type_id' => $offence['offence_type_id'],
                'created_by' => $actor->id,
            ]);
            $row->forceFill(['details' => $offence['details']])->save();
        }

        $current->reject(fn (ArrestPersonOffence $row) => in_array($row->offence_type_id, $wanted, true))
            ->each(fn (ArrestPersonOffence $row) => $row->delete());
    }
}
