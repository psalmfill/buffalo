<?php

namespace App\Actions\Persons;

use App\Enums\Gender;
use App\Enums\Role;
use App\Models\Community;
use App\Models\Lga;
use App\Models\Person;
use App\Models\PersonMerge;
use App\Models\User;
use App\Notifications\PersonMergeNotification;
use App\Support\Audit\AuditLog;
use App\Support\Jurisdiction\NotificationRecipients;
use App\Support\Persons\Merge\MergeBlocked;
use App\Support\Persons\Merge\MergeFields;
use App\Support\Persons\Merge\PersonMergeLocks;
use App\Support\RichText\RichText;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Undoes a person merge (Phase 10A.6), all or nothing, within
 * `buffalo.merges.undo_days` and while neither person has been merged
 * again (nor the survivor absorbed a later merge that is not undone):
 *
 * - every manifest row moves back exactly (reverse order), dropped rows
 *   are restored, combined rows get their prior values back; a row whose
 *   person link has changed since (moved elsewhere, deleted) aborts the
 *   whole undo with an explanation;
 * - a content column edited since the merge keeps its current value;
 * - aliases the merge added are removed (soft-deleted);
 * - revoked grants and cancelled requests are NOT restored;
 * - the source is restored (its own fields were never changed);
 * - the survivor's fields and place come back from survivor_prior_values,
 *   written through the model (ciphertext and blind indexes regenerated),
 *   except the fields edited since the merge that the executor keeps;
 * - survivor_prior_values is erased.
 */
final class UndoPersonMerge
{
    /** Columns that tie a row to a person: they must still point where the merge left them. */
    private const LINK_COLUMNS = ['person_id', 'associated_person_id', 'model_id', 'traditional_ruler_id', 'leader_person_id', 'merged_into_poi_id', 'arrest_person_id'];

    public function __construct(private readonly NotificationRecipients $recipients) {}

    /**
     * @param  list<string>  $keep  fields edited since the merge whose current value is kept
     *
     * @throws MergeBlocked
     * @throws AuthorizationException
     */
    public function __invoke(PersonMerge $merge, User $actor, string $reason, array $keep = []): void
    {
        if (! $actor->can('undo', $merge)) {
            throw new AuthorizationException('You may not undo this merge.');
        }

        $lgaIds = [];

        DB::transaction(function () use ($merge, $actor, $reason, $keep, &$lgaIds): void {
            /** @var PersonMerge $merge */
            $merge = PersonMerge::query()->whereKey($merge->id)->lockForUpdate()->firstOrFail();
            self::checkWindow($merge);

            $survivor = Person::withTrashed()->whereKey($merge->survivor_person_id)->first();
            $source = Person::withTrashed()->whereKey($merge->source_person_id)->first();

            if (! $survivor instanceof Person || ! $source instanceof Person) {
                throw new MergeBlocked('One of the records is no longer within your jurisdiction.');
            }

            [$survivor, $source] = PersonMergeLocks::lock($survivor, $source, $merge->ulid);

            if (! $actor->can('undo', $merge) || ! $actor->can('merge', $survivor)) {
                throw new AuthorizationException('You may not undo this merge.');
            }

            if ($survivor->trashed() || $survivor->merged_into_person_id !== null) {
                throw new MergeBlocked('The surviving record has since been merged or deleted, so this merge can no longer be undone.');
            }

            if (! $source->trashed() || $source->merged_into_person_id !== $survivor->id) {
                throw new MergeBlocked('The merged record has changed since the merge, so it can no longer be undone.');
            }

            $later = PersonMerge::query()->where('survivor_person_id', $survivor->id)->where('id', '>', $merge->id)->whereNull('undone_at')->exists();

            if ($later) {
                throw new MergeBlocked('Another record was merged into this one later. Undo that merge first.');
            }

            $values = self::priorValues($merge);
            $lgaIds = array_values(array_unique([$survivor->lga_id, (int) $values['before']['lga_id'], $source->lga_id]));

            try {
                $this->reverseManifest($merge, $survivor);

                DB::table('persons')->where('id', $source->id)->update([
                    'deleted_at' => null,
                    'merged_into_person_id' => null,
                    'merged_at' => null,
                    'merged_by' => null,
                ]);

                $this->restoreSurvivor($survivor, $values, $keep, $actor);
            } catch (QueryException $e) {
                if (in_array((string) $e->getCode(), ['23505', '23514', '23503', '23P01'], true)) {
                    throw new MergeBlocked('A record linked to this person can no longer be moved back without conflicting with a later change, so nothing was undone. ('.self::constraint($e).')');
                }

                throw $e;
            }

            $merge->forceFill([
                'undone_by' => $actor->id,
                'undone_at' => now(),
                'undo_reason' => trim($reason),
                'survivor_prior_values' => null,
            ])->save();

            AuditLog::event('person_merge_undone', 'A merge into this record was undone', $survivor, ['merge' => $merge->ulid, 'source' => $source->ulid, 'reason' => trim($reason)], $actor);
            AuditLog::event('person_merge_undone', 'This record was restored from a merge', $source->fresh() ?? $source, ['merge' => $merge->ulid, 'survivor' => $survivor->ulid, 'reason' => trim($reason)], $actor);
        });

        $this->notify($merge, $actor, $lgaIds);
    }

    /**
     * Fields of the survivor edited since the merge (current value differs
     * from the value the merge wrote), for the undo dialog. Values of
     * protected (encrypted) fields are never listed.
     *
     * @return list<array{field: string, label: string, protected: bool, current: string|null, before_merge: string|null}>
     */
    public static function editsSinceMerge(PersonMerge $merge, Person $survivor): array
    {
        try {
            $values = self::priorValues($merge);
        } catch (MergeBlocked) {
            return [];
        }

        $current = MergeFields::snapshot($survivor);
        $edits = [];

        foreach (self::undoFields() as $field => $label) {
            $keys = self::keysOf($field);

            if (self::pick($current, $keys) === self::pick($values['after'], $keys)) {
                continue;
            }

            $protected = in_array($field, MergeFields::PROTECTED, true);
            $edits[] = [
                'field' => $field,
                'label' => $label,
                'protected' => $protected,
                'current' => $protected ? null : self::display($field, $current),
                'before_merge' => $protected ? null : self::display($field, $values['before']),
            ];
        }

        return $edits;
    }

    /** @throws MergeBlocked */
    public static function checkWindow(PersonMerge $merge): void
    {
        if ($merge->isUndone()) {
            throw new MergeBlocked('This merge has already been undone.');
        }

        if (now()->greaterThan($merge->undoDeadline()) || $merge->survivor_prior_values === null) {
            throw new MergeBlocked('The undo window for this merge has passed.');
        }
    }

    /**
     * Fields the undo dialog can list: scalar fields, place, classification, deceased.
     *
     * @return array<string, string>
     */
    public static function undoFields(): array
    {
        return [...MergeFields::FIELDS, 'place' => 'Owning LGA', 'classification' => 'Classification', 'is_deceased' => 'Deceased'];
    }

    private function reverseManifest(PersonMerge $merge, Person $survivor): void
    {
        /** @var list<array{t: string, id: int, op: string, prior?: array<string, mixed>, after?: array<string, mixed>}> $entries */
        $entries = $merge->manifest['entries'] ?? [];

        foreach (array_reverse($entries) as $entry) {
            match ($entry['op']) {
                'update', 'drop' => $this->restoreRow($entry),
                'created' => DB::table($entry['t'])->where('id', $entry['id'])->where('person_id', $survivor->id)->whereNull('deleted_at')->update(['deleted_at' => now()]),
                // Not restored: revoked grants, cancelled requests, the
                // executed request, and the source (restored separately).
                default => null,
            };
        }
    }

    /**
     * @param  array{t: string, id: int, op: string, prior?: array<string, mixed>, after?: array<string, mixed>}  $entry
     */
    private function restoreRow(array $entry): void
    {
        $prior = $entry['prior'] ?? [];
        $after = $entry['after'] ?? [];
        $row = DB::table($entry['t'])->where('id', $entry['id'])->first(array_keys($prior));

        if ($row === null) {
            throw new MergeBlocked("A linked record ({$entry['t']}) no longer exists, so nothing was undone.");
        }

        $restore = [];

        foreach ($prior as $column => $value) {
            $current = $row->{$column};
            $unchanged = (string) json_encode($current) === (string) json_encode($after[$column] ?? null);

            if (in_array($column, self::LINK_COLUMNS, true) && ! $unchanged) {
                throw new MergeBlocked("A linked record ({$entry['t']}) has been moved since the merge, so nothing was undone.");
            }

            // A content column edited since the merge keeps its current value.
            if ($unchanged || $column === 'deleted_at') {
                $restore[$column] = $value;
            }
        }

        if ($restore !== []) {
            DB::table($entry['t'])->where('id', $entry['id'])->update($restore);
        }
    }

    /**
     * @param  array{before: array<string, mixed>, after: array<string, mixed>}  $values
     * @param  list<string>  $keep
     */
    private function restoreSurvivor(Person $survivor, array $values, array $keep, User $actor): void
    {
        $survivor->refresh();
        $current = MergeFields::snapshot($survivor);
        $target = [];

        foreach (array_keys(self::undoFields()) as $field) {
            $keys = self::keysOf($field);
            $edited = self::pick($current, $keys) !== self::pick($values['after'], $keys);

            foreach ($keys as $key) {
                $target[$key] = $edited && in_array($field, $keep, true) ? $current[$key] : $values['before'][$key];
            }
        }

        $community = $target['community_id'] === null ? null : Community::query()->whereKey($target['community_id'])->value('lga_id');

        if ($community !== null && (int) $community !== (int) $target['lga_id']) {
            $target['community_id'] = null;
        }

        $survivor->fill([
            'first_name' => $target['first_name'],
            'last_name' => $target['last_name'],
            'other_names' => $target['other_names'],
            'gender' => Gender::from((string) $target['gender']),
            'date_of_birth' => $target['date_of_birth'],
            'approximate_age' => $target['approximate_age'],
            'nin' => $target['nin'],
            'phone' => $target['phone'],
            'address' => $target['address'],
            'occupation' => $target['occupation'],
            'nationality' => $target['nationality'],
            // A snapshot taken before Phase 11B holds plain text.
            'description' => RichText::fromStored(is_string($target['description']) ? $target['description'] : null)?->html,
        ]);
        $survivor->forceFill([
            'state_id' => $target['state_id'],
            'lga_id' => $target['lga_id'],
            'community_id' => $target['community_id'],
            'classification' => $target['classification'],
            'is_deceased' => (bool) $target['is_deceased'],
            'updated_by' => $actor->id,
        ]);

        if ($survivor->isDirty()) {
            $survivor->save();
        }
    }

    /**
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     *
     * @throws MergeBlocked
     */
    private static function priorValues(PersonMerge $merge): array
    {
        if ($merge->survivor_prior_values === null) {
            throw new MergeBlocked('The undo window for this merge has passed.');
        }

        try {
            /** @var array{before: array<string, mixed>, after: array<string, mixed>} */
            return Crypt::decrypt($merge->survivor_prior_values);
        } catch (DecryptException) {
            throw new MergeBlocked('The saved values of this merge cannot be read, so it cannot be undone.');
        }
    }

    /** @return list<string> */
    private static function keysOf(string $field): array
    {
        return match ($field) {
            'place' => ['state_id', 'lga_id'],
            default => [MergeFields::snapshotKey($field)],
        };
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $keys
     * @return list<mixed>
     */
    private static function pick(array $values, array $keys): array
    {
        return array_map(fn (string $key) => $values[$key] ?? null, $keys);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private static function display(string $field, array $values): ?string
    {
        $value = match ($field) {
            'place' => Lga::query()->whereKey($values['lga_id'] ?? 0)->value('name'),
            'community' => Community::query()->whereKey($values['community_id'] ?? 0)->value('name'),
            'is_deceased' => ($values['is_deceased'] ?? false) ? 'Yes' : 'No',
            'gender' => Gender::tryFrom((string) ($values['gender'] ?? ''))?->label(),
            // Rich text (Phase 11B), or plain text in a snapshot from before.
            'description' => RichText::fromStored(is_string($values['description'] ?? null) ? $values['description'] : null)?->text,
            default => $values[$field] ?? null,
        };

        return $value === null ? null : (string) $value;
    }

    private static function constraint(QueryException $e): string
    {
        return preg_match('/constraint "([^"]+)"/', $e->getMessage(), $m) === 1 ? $m[1] : 'constraint';
    }

    /**
     * @param  list<int>  $lgaIds
     */
    private function notify(PersonMerge $merge, User $actor, array $lgaIds): void
    {
        $path = '/persons/'.Person::query()->whereKey($merge->survivor_person_id)->value('ulid');
        $officers = new Collection;

        foreach (Lga::query()->whereKey($lgaIds)->get() as $lga) {
            $officers = $officers->merge($this->recipients->resolve(Role::LgaSecurityChief, $lga))
                ->merge($this->recipients->resolve(Role::StateDirector, $lga));
        }

        // Revoked grants and cancelled requests are not restored, so their
        // holders are not notified again.
        foreach ($officers->unique('id')->reject(fn (User $user) => $user->id === $actor->id) as $user) {
            $user->notify(new PersonMergeNotification(PersonMergeNotification::UNDONE, $path));
        }
    }
}
