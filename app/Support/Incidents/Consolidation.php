<?php

namespace App\Support\Incidents;

use App\Enums\Classification;
use App\Models\AgencyFormation;
use App\Models\Arrest;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\IncidentUpdate;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Jurisdiction\OfficerNames;
use App\Support\RichText\RichText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Consolidating a duplicate incident into the original (Phase 10B.1).
 *
 * plan() lists every item on the duplicate with its outcome, as the user
 * may see it; apply() carries the same plan out (inside the caller's
 * transaction, after both incidents are locked) and returns the manifest.
 *
 * | Item | Outcome |
 * |---|---|
 * | Evidence | move (file, hash, custody and ULID unchanged; raised to the original's classification when lower) |
 * | Follow-ups | copy (append-only originals stay on the duplicate) |
 * | Persons | add, or skip when the same person with the same involvement is linked (unidentified: always add) |
 * | Groups, agencies, property | add, or skip when already present (notes appended, labelled) |
 * | Casualties | never: compared side by side only |
 * | Flashpoint and threat links | add where the user may link, else left |
 * | Access requests citing the duplicate | repoint |
 * | Arrests linked to the duplicate (Phase 11C) | repoint to the original |
 *
 * Items the user cannot see (above their clearance, or a linked record
 * outside their view) are "not_consolidated" and left in place, listed
 * without any detail. Every query that decides visibility runs through the
 * user's scopes and policies; rows are then moved by ID.
 */
final class Consolidation
{
    public const OUTCOMES = ['move', 'copy', 'add', 'skip', 'not_consolidated', 'left', 'repoint'];

    /**
     * @return list<array{kind: string, id: int, outcome: string, label: string|null, detail: string|null, target?: int}>
     */
    public function plan(Incident $duplicate, Incident $original, User $actor): array
    {
        $items = [];

        // Evidence and follow-ups: the scopes hide those above the user's clearance.
        $evidence = DB::table('incident_evidence')->where('incident_id', $duplicate->id)->whereNull('deleted_at')->orderBy('id')->pluck('id');
        $visibleEvidence = IncidentEvidence::query()->whereKey($evidence->all())->get()->keyBy('id');
        foreach ($evidence as $id) {
            $item = $visibleEvidence->get($id);
            $items[] = $item instanceof IncidentEvidence
                ? ['kind' => 'evidence', 'id' => (int) $id, 'outcome' => 'move', 'label' => $item->title, 'detail' => $item->evidence_type->label().' · '.$item->classificationLevel()->label()
                    .(self::higher($original, $item->classificationLevel()) ? ' (raised to '.$original->classificationLevel()->label().')' : '')]
                : self::hidden('evidence', (int) $id, 'above your clearance');
        }

        $updates = DB::table('incident_updates')->where('incident_id', $duplicate->id)->whereNull('copied_from_update_id')->orderBy('id')->pluck('id');
        $visibleUpdates = IncidentUpdate::query()->whereKey($updates->all())->get()->keyBy('id');
        foreach ($updates as $id) {
            $update = $visibleUpdates->get($id);
            $items[] = $update instanceof IncidentUpdate
                ? ['kind' => 'update', 'id' => (int) $id, 'outcome' => 'copy', 'label' => $update->type->label(), 'detail' => Str::limit($update->body, 120)]
                : self::hidden('update', (int) $id, 'above your clearance');
        }

        $existing = fn (string $table, array $columns) => DB::table($table)->where('incident_id', $original->id)->whereNull('deleted_at')->get(['id', ...$columns]);

        // Persons: linkToIncident (Phase 5), as when linking on the form.
        $ourPersons = $existing('incident_persons', ['person_id', 'involvement']);
        foreach (DB::table('incident_persons')->where('incident_id', $duplicate->id)->whereNull('deleted_at')->orderBy('id')->get() as $row) {
            if ($row->person_id === null) {
                $items[] = ['kind' => 'person', 'id' => (int) $row->id, 'outcome' => 'add', 'label' => 'Unidentified person', 'detail' => Str::limit((string) $row->unidentified_description, 120).' · '.$row->involvement];

                continue;
            }

            $person = Person::query()->whereKey($row->person_id)->first();

            if (! $person instanceof Person || ! $actor->can('linkToIncident', $person)) {
                $items[] = self::hidden('person', (int) $row->id, 'not visible to you');

                continue;
            }

            $same = $ourPersons->first(fn ($o) => (int) $o->person_id === (int) $row->person_id && $o->involvement === $row->involvement);
            $items[] = ['kind' => 'person', 'id' => (int) $row->id, 'outcome' => $same === null ? 'add' : 'skip', 'label' => $person->name, 'detail' => $row->involvement, ...($same === null ? [] : ['target' => (int) $same->id])];
        }

        $ourGroups = $existing('incident_criminal_groups', ['criminal_group_id']);
        foreach (DB::table('incident_criminal_groups')->where('incident_id', $duplicate->id)->whereNull('deleted_at')->orderBy('id')->get() as $row) {
            $group = CriminalGroup::query()->whereKey($row->criminal_group_id)->first();

            if (! $group instanceof CriminalGroup) {
                $items[] = self::hidden('group', (int) $row->id, 'not visible to you');

                continue;
            }

            $same = $ourGroups->first(fn ($o) => (int) $o->criminal_group_id === (int) $row->criminal_group_id);
            $items[] = ['kind' => 'group', 'id' => (int) $row->id, 'outcome' => $same === null ? 'add' : 'skip', 'label' => $group->name, 'detail' => $row->role, ...($same === null ? [] : ['target' => (int) $same->id])];
        }

        $ourAgencies = $existing('incident_agencies', ['security_agency_id', 'agency_formation_id']);
        foreach (DB::table('incident_agencies')->where('incident_id', $duplicate->id)->whereNull('deleted_at')->orderBy('id')->get() as $row) {
            $formation = $row->agency_formation_id === null ? null : AgencyFormation::query()->whereKey($row->agency_formation_id)->first();

            if ($row->agency_formation_id !== null && ! $formation instanceof AgencyFormation) {
                $items[] = self::hidden('agency', (int) $row->id, 'not visible to you');

                continue;
            }

            $same = $ourAgencies->first(fn ($o) => (int) $o->security_agency_id === (int) $row->security_agency_id && $o->agency_formation_id === $row->agency_formation_id);
            $agency = (string) DB::table('security_agencies')->where('id', $row->security_agency_id)->value('name');
            $items[] = ['kind' => 'agency', 'id' => (int) $row->id, 'outcome' => $same === null ? 'add' : 'skip', 'label' => $agency.($formation ? ' · '.$formation->name : ''), 'detail' => $row->role, ...($same === null ? [] : ['target' => (int) $same->id])];
        }

        $ourProperties = $existing('incident_properties', ['property_type_id', 'organization_site_id']);
        foreach (DB::table('incident_properties')->where('incident_id', $duplicate->id)->whereNull('deleted_at')->orderBy('id')->get() as $row) {
            $site = $row->organization_site_id === null ? null : OrganizationSite::query()->whereKey($row->organization_site_id)->first();

            if ($row->organization_site_id !== null && ! $site instanceof OrganizationSite) {
                $items[] = self::hidden('property', (int) $row->id, 'not visible to you');

                continue;
            }

            $same = $ourProperties->first(fn ($o) => (int) $o->property_type_id === (int) $row->property_type_id && $o->organization_site_id === $row->organization_site_id);
            $type = (string) DB::table('property_types')->where('id', $row->property_type_id)->value('name');
            $items[] = ['kind' => 'property', 'id' => (int) $row->id, 'outcome' => $same === null ? 'add' : 'skip', 'label' => $type.($site ? ' · '.$site->name : ''), 'detail' => $row->description === null ? null : Str::limit((string) $row->description, 120), ...($same === null ? [] : ['target' => (int) $same->id])];
        }

        foreach (DB::table('flashpoint_incidents')->where('incident_id', $duplicate->id)->whereNull('deleted_at')->orderBy('id')->get() as $row) {
            $flashpoint = Flashpoint::query()->whereKey($row->flashpoint_id)->first();

            if (! $flashpoint instanceof Flashpoint || ! $actor->can('linkIncident', [$flashpoint, $original])) {
                $items[] = self::hidden('flashpoint', (int) $row->id, 'you may not link it', 'left');

                continue;
            }

            $linked = DB::table('flashpoint_incidents')->where('flashpoint_id', $row->flashpoint_id)->where('incident_id', $original->id)->whereNull('deleted_at')->value('id');
            $items[] = ['kind' => 'flashpoint', 'id' => (int) $row->id, 'outcome' => $linked === null ? 'add' : 'skip', 'label' => $flashpoint->name, 'detail' => null, ...($linked === null ? [] : ['target' => (int) $linked])];
        }

        foreach (DB::table('threat_report_incidents')->where('incident_id', $duplicate->id)->whereNull('deleted_at')->orderBy('id')->get() as $row) {
            $threat = ThreatReport::query()->whereKey($row->threat_report_id)->first();

            if (! $threat instanceof ThreatReport || ! $actor->can('linkIncident', [$threat, $original])) {
                $items[] = self::hidden('threat', (int) $row->id, 'you may not link it', 'left');

                continue;
            }

            $linked = DB::table('threat_report_incidents')->where('threat_report_id', $row->threat_report_id)->where('incident_id', $original->id)->whereNull('deleted_at')->value('id');
            $items[] = ['kind' => 'threat', 'id' => (int) $row->id, 'outcome' => $linked === null ? 'add' : 'skip', 'label' => $threat->title, 'detail' => $row->relation, ...($linked === null ? [] : ['target' => (int) $linked])];
        }

        // Arrests linked to the duplicate (Phase 11C): moved to the original.
        $arrests = DB::table('arrests')->where('incident_id', $duplicate->id)->whereNull('deleted_at')->orderBy('id')->pluck('id');
        $visibleArrests = Arrest::query()->whereKey($arrests->all())->get()->keyBy('id');
        foreach ($arrests as $id) {
            $arrest = $visibleArrests->get($id);
            $items[] = $arrest instanceof Arrest
                ? ['kind' => 'arrest', 'id' => (int) $id, 'outcome' => 'repoint', 'label' => $arrest->reference_no, 'detail' => 'Linked to the original incident instead']
                : self::hidden('arrest', (int) $id, 'outside your view');
        }

        // Access requests that cite the duplicate as their reason: repointed.
        foreach (DB::table('record_access_requests')->where('incident_id', $duplicate->id)->orderBy('id')->pluck('id') as $id) {
            $items[] = ['kind' => 'access_request', 'id' => (int) $id, 'outcome' => 'repoint', 'label' => 'Access request citing this incident', 'detail' => null];
        }

        return $items;
    }

    /**
     * Carries out a plan (inside the caller's transaction, both incidents
     * locked, the duplicate not yet invalid).
     *
     * @param  list<array{kind: string, id: int, outcome: string, label: string|null, detail: string|null, target?: int}>  $plan
     * @return list<array<string, mixed>> the manifest: every item with its outcome and, for added or copied items, the new row
     */
    public function apply(array $plan, Incident $duplicate, Incident $original, User $actor): array
    {
        $from = (string) $duplicate->reference_no;
        $label = "[From {$from}]";
        $manifest = [];

        foreach ($plan as $item) {
            $entry = ['kind' => $item['kind'], 'id' => $item['id'], 'outcome' => $item['outcome']];

            match ([$item['kind'], $item['outcome']]) {
                ['evidence', 'move'] => $entry += $this->moveEvidence($item['id'], $duplicate, $original, $actor),
                ['update', 'copy'] => $entry['new_id'] = $this->copyUpdate($item['id'], $original, $actor),
                ['person', 'add'] => $entry['new_id'] = $this->addChild('incident_persons', $item['id'], $original, $actor, fn ($row) => [
                    'person_id' => $row->person_id,
                    'unidentified_description' => $row->person_id === null ? trim((string) $row->unidentified_description)." (from {$from})" : null,
                    'involvement' => $row->involvement,
                    'notes' => $row->notes,
                ]),
                ['group', 'add'] => $entry['new_id'] = $this->addChild('incident_criminal_groups', $item['id'], $original, $actor, fn ($row) => [
                    'criminal_group_id' => $row->criminal_group_id, 'role' => $row->role, 'notes' => $row->notes,
                ]),
                ['agency', 'add'] => $entry['new_id'] = $this->addChild('incident_agencies', $item['id'], $original, $actor, fn ($row) => [
                    'security_agency_id' => $row->security_agency_id, 'agency_formation_id' => $row->agency_formation_id,
                    'role' => $row->role, 'responded_at' => $row->responded_at, 'notes' => $row->notes,
                ]),
                ['property', 'add'] => $entry['new_id'] = $this->addChild('incident_properties', $item['id'], $original, $actor, fn ($row) => [
                    'property_type_id' => $row->property_type_id, 'description' => $row->description, 'quantity' => $row->quantity,
                    'estimated_value_naira' => $row->estimated_value_naira, 'organization_site_id' => $row->organization_site_id,
                ]),
                ['person', 'skip'] => $entry += $this->appendNotes('incident_persons', 'notes', $item, $label),
                ['group', 'skip'] => $entry += $this->appendNotes('incident_criminal_groups', 'notes', $item, $label),
                ['agency', 'skip'] => $entry += $this->appendNotes('incident_agencies', 'notes', $item, $label),
                ['property', 'skip'] => $entry += $this->appendNotes('incident_properties', 'description', $item, $label),
                ['flashpoint', 'add'] => $entry['new_id'] = $this->addLink('flashpoint_incidents', 'flashpoint_id', 'flashpoints', $item['id'], $original, $actor, ['link_note' => "Consolidated from {$from}."]),
                ['threat', 'add'] => $entry['new_id'] = $this->addLink('threat_report_incidents', 'threat_report_id', 'threat_reports', $item['id'], $original, $actor, []),
                ['access_request', 'repoint'] => DB::table('record_access_requests')->where('id', $item['id'])->update(['incident_id' => $original->id]),
                ['arrest', 'repoint'] => $entry += $this->moveArrest($item['id'], $original, $actor),
                default => null, // skip without notes, not consolidated, left
            };

            $manifest[] = $entry;
        }

        return $manifest;
    }

    /**
     * An arrest now links to the original incident (Phase 11C), with a
     * named event on the arrest.
     *
     * @return array<string, mixed>
     */
    private function moveArrest(int $id, Incident $original, User $actor): array
    {
        $arrest = Arrest::query()->whereKey($id)->firstOrFail();
        $from = $arrest->incident_id;
        DB::table('arrests')->where('id', $id)->update(['incident_id' => $original->id, 'updated_by' => $actor->id, 'updated_at' => now()]);
        AuditLog::event('incident_consolidated', 'Linked to the original incident when its duplicate was consolidated', $arrest, ['to' => $original->ulid], $actor);

        return ['from_incident_id' => $from];
    }

    /**
     * @return array<string, mixed>
     */
    private function moveEvidence(int $id, Incident $duplicate, Incident $original, User $actor): array
    {
        $evidence = IncidentEvidence::withTrashed()->whereKey($id)->firstOrFail();
        $old = $evidence->classificationLevel();
        $new = self::higher($original, $old) ? $original->classificationLevel() : $old;

        DB::table('incident_evidence')->where('id', $id)->update([
            'incident_id' => $original->id,
            'state_id' => $original->state_id,
            'lga_id' => $original->lga_id,
            'classification' => $new->value,
            'consolidated_from_incident_id' => $duplicate->id,
            'updated_by' => $actor->id,
            'updated_at' => now(),
        ]);

        // Custody: the move, and a raised classification (Phase 5B event).
        AuditLog::event('consolidated', "Moved from {$duplicate->reference_no} to {$original->reference_no}", $evidence, ['from' => $duplicate->reference_no, 'to' => $original->reference_no], $actor);

        if ($new !== $old) {
            AuditLog::event('classification_changed', 'Classification raised to match the incident it was consolidated into', $evidence, ['old' => $old->value, 'new' => $new->value], $actor);
        }

        return ['from_classification' => $old->value, 'to_classification' => $new->value];
    }

    private function copyUpdate(int $id, Incident $original, User $actor): int
    {
        $source = IncidentUpdate::query()->whereKey($id)->firstOrFail();
        $level = self::higher($original, $source->classificationLevel()) ? $original->classificationLevel() : $source->classificationLevel();

        $copy = new IncidentUpdate;
        $copy->forceFill([
            'incident_id' => $original->id,
            'state_id' => $original->state_id,
            'lga_id' => $original->lga_id,
            'type' => $source->type,
            // A copy is a new row, so it is rich text (Phase 11B); a plain text
            // original (written before 11B) is converted, line breaks kept.
            'body' => $source->isHtml() ? $source->body : RichText::fromPlainText($source->body)?->html,
            // Never below the incident (5C trigger): raised when the original is higher.
            'classification' => $level,
            'created_by' => $actor->id,
            'copied_from_update_id' => $source->id,
        ])->save();

        return $copy->id;
    }

    /**
     * @param  \Closure(\stdClass): array<string, mixed>  $columns
     */
    private function addChild(string $table, int $id, Incident $original, User $actor, \Closure $columns): int
    {
        $row = DB::table($table)->where('id', $id)->first();

        return (int) DB::table($table)->insertGetId([
            'ulid' => strtolower((string) Str::ulid()),
            'incident_id' => $original->id,
            'state_id' => $original->state_id,
            'lga_id' => $original->lga_id,
            ...$columns($row),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Notes of a skipped row appended, labelled, to the original's row.
     *
     * @param  array{kind: string, id: int, outcome: string, target?: int}  $item
     * @return array<string, mixed>
     */
    private function appendNotes(string $table, string $column, array $item, string $label): array
    {
        $theirs = trim((string) DB::table($table)->where('id', $item['id'])->value($column));
        $target = $item['target'] ?? null;

        if ($theirs === '' || $target === null) {
            return ['target' => $target];
        }

        $ours = trim((string) DB::table($table)->where('id', $target)->value($column));

        if ($ours === $theirs || str_contains($ours, "{$label} {$theirs}")) {
            return ['target' => $target];
        }

        DB::table($table)->where('id', $target)->update([$column => $ours === '' ? "{$label} {$theirs}" : "{$ours}\n\n{$label} {$theirs}", 'updated_at' => now()]);

        return ['target' => $target, 'notes_appended' => true];
    }

    /**
     * A flashpoint or threat link added to the original, in the linked
     * record's place (links belong to the flashpoint or threat).
     *
     * @param  array<string, mixed>  $extra
     */
    private function addLink(string $table, string $column, string $parentTable, int $id, Incident $original, User $actor, array $extra): int
    {
        $row = DB::table($table)->where('id', $id)->first();
        $parent = DB::table($parentTable)->where('id', $row->{$column})->first(['state_id', 'lga_id']);

        return (int) DB::table($table)->insertGetId([
            'ulid' => strtolower((string) Str::ulid()),
            $column => $row->{$column},
            'state_id' => $parent->state_id,
            'lga_id' => $parent->lga_id,
            'incident_id' => $original->id,
            ...($table === 'threat_report_incidents' ? ['relation' => $row->relation] : []),
            ...$extra,
            'linked_by' => $actor->id,
            'linked_at' => now(),
        ]);
    }

    private static function higher(Incident $original, Classification $level): bool
    {
        return $original->classificationLevel()->rank() > $level->rank();
    }

    /**
     * @return array{kind: string, id: int, outcome: string, label: null, detail: string}
     */
    private static function hidden(string $kind, int $id, string $why, string $outcome = 'not_consolidated'): array
    {
        return ['kind' => $kind, 'id' => $id, 'outcome' => $outcome, 'label' => null, 'detail' => $why];
    }

    /**
     * The attribution of a copied follow-up: "Originally recorded on BUF/…
     * by [name] at [time]".
     */
    public static function attribution(IncidentUpdate $copy): ?string
    {
        if ($copy->copied_from_update_id === null) {
            return null;
        }

        $source = DB::table('incident_updates')->where('id', $copy->copied_from_update_id)->first(['incident_id', 'created_by', 'created_at']);

        if ($source === null) {
            return null;
        }

        $reference = DB::table('incidents')->where('id', $source->incident_id)->value('reference_no');
        $name = OfficerNames::for([(int) $source->created_by])->get((int) $source->created_by);
        $at = CarbonImmutable::parse((string) $source->created_at)->setTimezone((string) config('app.timezone'))->format('j M Y H:i');

        return "Originally recorded on {$reference} by {$name} at {$at}";
    }
}
