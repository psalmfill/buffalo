<?php

namespace App\Http\Presenters;

use App\Enums\AgencyType;
use App\Enums\IncidentCategory;
use App\Enums\Severity;
use App\Models\Denomination;
use App\Models\IncidentType;
use App\Models\ReferenceModel;
use App\Models\Religion;
use App\Models\SecurityAgency;
use App\Models\State;
use App\Models\ThreatType;
use App\Support\ReferenceData\ReferenceTable;

/**
 * Reference values for the browser: ULIDs and labels, never numeric ids.
 * Also describes each table's extra columns and form fields, so one set of
 * pages serves every table.
 */
final class ReferenceValuePresenter
{
    /**
     * Relations to eager-load for rows and forms.
     *
     * @return list<string>
     */
    public static function relations(ReferenceTable $table): array
    {
        return match ($table) {
            ReferenceTable::SecurityAgencies => ['state'],
            ReferenceTable::Denominations => ['religion'],
            ReferenceTable::Religions => ['denominations'],
            default => [],
        };
    }

    /**
     * @return array{slug: string, label: string, singular: string}
     */
    public static function table(ReferenceTable $table): array
    {
        return ['slug' => $table->value, 'label' => $table->label(), 'singular' => $table->singular()];
    }

    /**
     * Extra list columns (beyond name, code and status).
     *
     * @return list<array{key: string, label: string}>
     */
    public static function columns(ReferenceTable $table): array
    {
        return match ($table) {
            ReferenceTable::IncidentTypes => [
                ['key' => 'category', 'label' => 'Category'],
                ['key' => 'default_severity', 'label' => 'Default severity'],
                ['key' => 'fields', 'label' => 'Detail fields'],
            ],
            ReferenceTable::ThreatTypes => [['key' => 'category', 'label' => 'Category']],
            ReferenceTable::SecurityAgencies => [
                ['key' => 'short_name', 'label' => 'Short name'],
                ['key' => 'agency_type', 'label' => 'Type'],
                ['key' => 'state', 'label' => 'State'],
            ],
            ReferenceTable::Denominations => [['key' => 'religion', 'label' => 'Religion']],
            ReferenceTable::Religions => [['key' => 'denominations', 'label' => 'Denominations']],
            ReferenceTable::PropertyTypes, ReferenceTable::OrganizationSectors => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(ReferenceModel $value): array
    {
        return [
            'ulid' => $value->ulid,
            'name' => $value->name,
            'code' => $value->code,
            'description' => $value->description,
            'sort_order' => $value->sort_order,
            'is_active' => $value->is_active,
            'extras' => self::extras($value),
        ];
    }

    /**
     * Current values for the edit form.
     *
     * @return array<string, mixed>
     */
    public static function formValues(ReferenceModel $value): array
    {
        return [
            'name' => $value->name,
            'code' => $value->code,
            'description' => $value->description ?? '',
            'sort_order' => $value->sort_order,
            ...match (true) {
                $value instanceof IncidentType => ['category' => $value->category->value, 'default_severity' => $value->default_severity->value],
                $value instanceof ThreatType => ['category' => $value->category->value],
                $value instanceof SecurityAgency => ['short_name' => $value->short_name, 'agency_type' => $value->agency_type->value, 'state' => $value->state->ulid ?? ''],
                $value instanceof Denomination => ['religion' => $value->religion->ulid],
                default => [],
            },
        ];
    }

    /**
     * The table's extra form fields. Select options are the ACTIVE values,
     * plus $value's current selection if it has since been deactivated.
     *
     * @return list<array<string, mixed>>
     */
    public static function fields(ReferenceTable $table, ?ReferenceModel $value = null): array
    {
        return match ($table) {
            ReferenceTable::IncidentTypes => [
                self::select('category', 'Category', self::enumOptions(IncidentCategory::cases())),
                self::select('default_severity', 'Default severity', self::enumOptions(Severity::cases())),
            ],
            ReferenceTable::ThreatTypes => [
                self::select('category', 'Category', self::enumOptions(IncidentCategory::cases())),
            ],
            ReferenceTable::SecurityAgencies => [
                ['name' => 'short_name', 'label' => 'Short name', 'type' => 'text', 'required' => true],
                self::select('agency_type', 'Type', self::enumOptions(AgencyType::cases())),
                [
                    ...self::select('state', 'State', self::recordOptions(
                        State::class,
                        $value instanceof SecurityAgency ? $value->state : null,
                    )),
                    'showWhen' => ['field' => 'agency_type', 'equals' => AgencyType::State->value],
                    'help' => 'State outfits only.',
                ],
            ],
            ReferenceTable::Denominations => [
                [
                    ...self::select('religion', 'Religion', self::recordOptions(
                        Religion::class,
                        $value instanceof Denomination ? $value->religion : null,
                    )),
                    'createOnly' => true,
                ],
            ],
            ReferenceTable::Religions, ReferenceTable::PropertyTypes, ReferenceTable::OrganizationSectors => [],
        };
    }

    /**
     * @return array<string, string|null>
     */
    private static function extras(ReferenceModel $value): array
    {
        return match (true) {
            $value instanceof IncidentType => [
                'category' => $value->category->label(),
                'default_severity' => $value->default_severity->label(),
                'fields' => (string) collect($value->details_schema)->reject(fn (array $field): bool => (bool) ($field['deprecated'] ?? false))->count(),
            ],
            $value instanceof ThreatType => ['category' => $value->category->label()],
            $value instanceof SecurityAgency => [
                'short_name' => $value->short_name,
                'agency_type' => $value->agency_type->label(),
                'state' => $value->state?->name,
            ],
            $value instanceof Denomination => ['religion' => $value->religion->name],
            $value instanceof Religion => ['denominations' => (string) $value->denominations->count()],
            default => [],
        };
    }

    /**
     * @param  list<array{value: string, label: string}>  $options
     * @return array<string, mixed>
     */
    private static function select(string $name, string $label, array $options): array
    {
        return ['name' => $name, 'label' => $label, 'type' => 'select', 'required' => true, 'options' => $options];
    }

    /**
     * @param  list<IncidentCategory|Severity|AgencyType>  $cases
     * @return list<array{value: string, label: string}>
     */
    private static function enumOptions(array $cases): array
    {
        return array_map(fn (IncidentCategory|Severity|AgencyType $case): array => ['value' => $case->value, 'label' => $case->label()], $cases);
    }

    /**
     * @param  class-string<State|Religion>  $model
     * @return list<array{value: string, label: string}>
     */
    private static function recordOptions(string $model, State|Religion|null $current): array
    {
        $options = $model::query()->active()->orderBy('name')->get(['ulid', 'name'])
            ->map(fn (State|Religion $record): array => ['value' => $record->ulid, 'label' => $record->name])
            ->all();

        if ($current !== null && ! $current->is_active) {
            $options[] = ['value' => $current->ulid, 'label' => $current->name.' (inactive)'];
        }

        return array_values($options);
    }
}
