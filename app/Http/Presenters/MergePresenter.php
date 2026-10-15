<?php

namespace App\Http\Presenters;

use App\Actions\Persons\UndoPersonMerge;
use App\Enums\DuplicateStrength;
use App\Enums\PoiStatus;
use App\Models\Media;
use App\Models\Person;
use App\Models\PersonMerge;
use App\Models\PersonMergeRequest;
use App\Models\User;
use App\Support\Jurisdiction\OfficerNames;
use App\Support\Persons\Merge\MergeFields;
use App\Support\Persons\Merge\MergePlan;
use App\Support\Persons\Merge\PersonDuplicateFinder;
use App\Support\RichText\RichText;
use Illuminate\Support\Facades\DB;

/**
 * Duplicate candidates, the merge review page, merge requests and merged
 * records (Phase 10A). Persons are identified by ULID only.
 */
final class MergePresenter
{
    /**
     * @param  array{a: Person, b: Person, strength: DuplicateStrength, signals: list<string>, name_score: float}  $pair
     * @param  array<string, string>  $pending  "loId|hiId" => request ULID
     * @return array<string, mixed>
     */
    public static function pair(array $pair, User $actor, array $pending): array
    {
        $key = min($pair['a']->id, $pair['b']->id).'|'.max($pair['a']->id, $pair['b']->id);

        return [
            'a' => PersonPresenter::row($pair['a']),
            'b' => PersonPresenter::row($pair['b']),
            'strength' => ['value' => $pair['strength']->value, 'label' => $pair['strength']->label()],
            'signals' => array_map(fn (string $signal) => PersonDuplicateFinder::signalLabel($signal), $pair['signals']),
            'pendingRequest' => $pending[$key] ?? null,
            'can' => [
                'request' => ! isset($pending[$key]) && $actor->can('requestMerge', $pair['a']) && $actor->can('requestMerge', $pair['b']),
                'merge' => $actor->can('merge', $pair['a']) && $actor->can('merge', $pair['b']),
            ],
        ];
    }

    /**
     * The review page: both records side by side, the conflicts, what
     * moves, what ends, and the visibility side-effect.
     *
     * @return array<string, mixed>
     */
    public static function review(Person $survivor, Person $source): array
    {
        $fields = [];

        foreach (MergeFields::FIELDS as $field => $label) {
            $fields[] = [
                'field' => $field,
                'label' => $label,
                'protected' => in_array($field, MergeFields::PROTECTED, true),
                'survivor' => self::display($survivor, $field),
                'source' => self::display($source, $field),
                'conflict' => MergeFields::conflicts($survivor, $source, $field),
                'automatic' => MergeFields::automatic($survivor, $source, $field),
            ];
        }

        $poi = fn (Person $person) => DB::table('persons_of_interest')->where('person_id', $person->id)->whereNull('deleted_at')->value('status');
        [$ourPoi, $theirPoi] = [$poi($survivor), $poi($source)];
        $photo = fn (Person $person): ?string => ($current = $person->currentPhoto()) instanceof Media ? PersonPresenter::photoUrl($person, $current, 'thumb') : null;

        return [
            'survivor' => self::side($survivor),
            'source' => self::side($source),
            'fields' => $fields,
            'blockers' => MergePlan::blockers($survivor, $source),
            'dobConflict' => MergePlan::dobConflict($survivor, $source),
            'deceasedDiffers' => MergePlan::deceasedDiffers($survivor, $source),
            'classification' => PersonPresenter::option(MergePlan::classification($survivor, $source)),
            'counts' => MergePlan::counts($source),
            'endings' => MergePlan::endings($source),
            'visibility' => MergePlan::visibility($survivor, $source),
            'photos' => ['survivor' => $photo($survivor), 'source' => $photo($source)],
            'poi' => $ourPoi !== null && $theirPoi !== null ? [
                'survivor' => PoiStatus::from((string) $ourPoi)->label(),
                'source' => PoiStatus::from((string) $theirPoi)->label(),
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function side(Person $person): array
    {
        $person->loadMissing(['lga', 'state']);

        return [
            ...PersonPresenter::row($person),
            'lga' => ['ulid' => $person->lga->ulid, 'name' => $person->lga->name],
            'created_at' => $person->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function request(PersonMergeRequest $request, User $actor): array
    {
        $a = $request->personA;
        $b = $request->personB;
        $names = OfficerNames::for([$request->requested_by, $request->decided_by]);

        return [
            'ulid' => $request->ulid,
            'a' => $a instanceof Person ? PersonPresenter::row($a) : null,
            'b' => $b instanceof Person ? PersonPresenter::row($b) : null,
            'suggestedSurvivor' => self::suggested($request, $a, $b),
            'reason' => $request->reason,
            'status' => ['value' => $request->status->value, 'label' => $request->status->label()],
            'requestedBy' => (string) $names->get($request->requested_by),
            'lga' => $request->lga?->name,
            'createdAt' => $request->created_at?->toIso8601String(),
            'decidedBy' => $request->decided_by === null ? null : $names->get($request->decided_by),
            'decidedAt' => $request->decided_at?->toIso8601String(),
            'decisionNote' => $request->decision_note,
            'can' => [
                'decide' => $a instanceof Person && $b instanceof Person && $actor->can('decide', $request),
            ],
        ];
    }

    /**
     * A merge listed on the survivor's page ("Merged records").
     *
     * @return array<string, mixed>
     */
    public static function merged(PersonMerge $merge, User $actor, Person $survivor): array
    {
        $source = $merge->source;
        $undoable = ! $merge->isUndone() && $merge->survivor_prior_values !== null && now()->lessThanOrEqualTo($merge->undoDeadline()) && $actor->can('undo', $merge);
        // Officers at state or national level are outside an LGA chief's user scope: names only.
        $names = OfficerNames::for([$merge->executed_by, $merge->undone_by]);

        return [
            'ulid' => $merge->ulid,
            'source' => $source instanceof Person ? ['ulid' => $source->ulid, 'name' => $source->name] : null,
            'executedAt' => $merge->executed_at->toIso8601String(),
            'executedBy' => (string) $names->get($merge->executed_by),
            'undoneAt' => $merge->undone_at?->toIso8601String(),
            'undoneBy' => $merge->undone_by === null ? null : $names->get($merge->undone_by),
            'undoReason' => $merge->undo_reason,
            'undoDeadline' => $merge->isUndone() ? null : $merge->undoDeadline()->toIso8601String(),
            'can' => ['undo' => $undoable],
            // The fields edited since the merge (values of protected fields never listed).
            'edits' => $undoable ? UndoPersonMerge::editsSinceMerge($merge, $survivor) : [],
        ];
    }

    private static function suggested(PersonMergeRequest $request, ?Person $a, ?Person $b): ?string
    {
        foreach ([$a, $b] as $person) {
            if ($person instanceof Person && $person->id === $request->suggested_survivor_id) {
                return $person->ulid;
            }
        }

        return null;
    }

    private static function display(Person $person, string $field): ?string
    {
        if (! MergeFields::hasValue($person, $field)) {
            return null;
        }

        return match ($field) {
            'gender' => $person->gender->label(),
            'community' => $person->community?->name,
            // Rich text (Phase 11B): compared side by side as plain text.
            'description' => RichText::fromStored($person->description)?->text,
            default => (string) MergeFields::value($person, $field),
        };
    }
}
