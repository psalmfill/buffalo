<?php

namespace App\Http\Presenters;

use App\Enums\Classification;
use App\Enums\FormationStatus;
use App\Enums\FormationType;
use App\Models\AgencyFormation;
use App\Models\SecurityAgency;
use Illuminate\Support\Collection;

/**
 * Agency formations for the browser (Phase 4A): ULIDs and labels, never ids.
 */
final class AgencyFormationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(AgencyFormation $formation): array
    {
        return [
            'ulid' => $formation->ulid,
            'name' => $formation->name,
            'agency' => self::agency($formation->agency),
            'formation_type' => ['value' => $formation->formation_type->value, 'label' => $formation->formation_type->label()],
            'status' => ['value' => $formation->status->value, 'label' => $formation->status->label()],
            'classification' => ['value' => $formation->classificationLevel()->value, 'label' => $formation->classificationLevel()->label()],
            'lga' => ['ulid' => $formation->lga->ulid, 'name' => $formation->lga->name],
            'state' => ['ulid' => $formation->state->ulid, 'name' => $formation->state->name],
            'is_temporary' => $formation->is_temporary,
            'active_from' => $formation->active_from?->toDateString(),
            'active_to' => $formation->active_to?->toDateString(),
            'personnel_strength' => $formation->personnel_strength,
            'latitude' => $formation->getAttribute('latitude') === null ? null : (float) $formation->getAttribute('latitude'),
            'longitude' => $formation->getAttribute('longitude') === null ? null : (float) $formation->getAttribute('longitude'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(AgencyFormation $formation, ?AgencyFormation $visibleParent): array
    {
        return [
            ...self::row($formation),
            'parent' => $visibleParent === null ? null : ['ulid' => $visibleParent->ulid, 'name' => $visibleParent->name],
            // A parent is recorded that the viewer cannot see (another LGA or
            // a higher classification): say so without naming it.
            'parent_hidden' => $formation->parent_formation_id !== null && $visibleParent === null,
            'community' => $formation->community === null ? null : ['ulid' => $formation->community->ulid, 'name' => $formation->community->name],
            'address' => $formation->address,
            'official_phone' => $formation->official_phone,
            'commander_title' => $formation->commander_title,
            'commander_name' => $formation->commander_name,
            'commander_rank' => $formation->commander_rank,
            'notes' => $formation->notes,
            'created_at' => $formation->created_at?->toIso8601String(),
            'updated_at' => $formation->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array{ulid: string, name: string, short_name: string|null}
     */
    public static function agency(SecurityAgency $agency): array
    {
        return ['ulid' => $agency->ulid, 'name' => $agency->name, 'short_name' => $agency->short_name];
    }

    /**
     * Agencies to choose from: active ones, plus $keep (a current value).
     * State agencies carry their state's ULID so the form can filter them.
     *
     * @return list<array{value: string, label: string, state: string|null}>
     */
    public static function agencyOptions(?SecurityAgency $keep = null): array
    {
        /** @var Collection<int, SecurityAgency> $agencies */
        $agencies = SecurityAgency::query()
            ->with('state:id,ulid')
            ->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($w) => $w->orWhere('id', $keep?->id)))
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        return array_values($agencies->map(fn (SecurityAgency $a) => [
            'value' => $a->ulid,
            'label' => "{$a->name} ({$a->short_name})",
            'state' => $a->state?->ulid,
        ])->all());
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        $option = fn (FormationType|FormationStatus|Classification $c) => ['value' => $c->value, 'label' => $c->label()];

        return [
            'types' => array_map($option, FormationType::cases()),
            'statuses' => array_map($option, FormationStatus::cases()),
            'classifications' => array_map($option, Classification::cases()),
        ];
    }
}
