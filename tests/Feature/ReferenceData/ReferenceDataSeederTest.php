<?php

use App\Enums\AgencyType;
use App\Enums\IncidentCategory;
use App\Enums\Severity;
use App\Models\Denomination;
use App\Models\IncidentType;
use App\Models\OrganizationSector;
use App\Models\PropertyType;
use App\Models\Religion;
use App\Models\SecurityAgency;
use App\Models\ThreatType;
use App\Support\DynamicFields\SchemaValidator;
use Database\Seeders\ReferenceDataSeeder;

function counts(): array
{
    return [
        'incident_types' => IncidentType::query()->count(),
        'threat_types' => ThreatType::query()->count(),
        'security_agencies' => SecurityAgency::query()->count(),
        'religions' => Religion::query()->count(),
        'denominations' => Denomination::query()->count(),
        'property_types' => PropertyType::query()->count(),
        'organization_sectors' => OrganizationSector::query()->count(),
    ];
}

test('the seeder creates every default', function () {
    $this->seed(ReferenceDataSeeder::class);

    expect(counts())->toBe([
        'incident_types' => 15,
        'threat_types' => 14,
        'security_agencies' => 13,
        'religions' => 4,
        'denominations' => 9,
        'property_types' => 12,
        'organization_sectors' => 19,
    ]);

    $kidnapping = IncidentType::query()->where('code', 'kidnapping')->sole();
    expect($kidnapping->category)->toBe(IncidentCategory::Crime)
        ->and($kidnapping->default_severity)->toBe(Severity::Critical)
        ->and(array_column($kidnapping->details_schema, 'key'))->toBe(['location_type', 'ransom_demanded', 'ransom_amount_naira'])
        ->and($kidnapping->schema_version)->toBe(1);

    expect(IncidentType::query()->where('code', 'other_security_incident')->sole()->details_schema)->toBe([])
        ->and(IncidentType::query()->where('code', 'road_mishap')->sole()->details_schema[1]['options'])->toHaveCount(7)
        ->and(SecurityAgency::query()->where('short_name', 'NSCDC')->sole()->agency_type)->toBe(AgencyType::Federal)
        ->and(SecurityAgency::query()->whereNotNull('state_id')->exists())->toBeFalse()
        ->and(Religion::query()->where('code', 'islam')->sole()->denominations()->pluck('code')->all())->toBe(['sunni', 'shia', 'other_islamic'])
        ->and(Religion::query()->where('code', 'traditional_religion')->sole()->denominations()->count())->toBe(0);
});

test('every seeded incident type schema passes the schema validator', function () {
    $this->seed(ReferenceDataSeeder::class);

    foreach (IncidentType::query()->get() as $type) {
        expect(app(SchemaValidator::class)->validate($type->details_schema))->toBeArray();
    }
});

test('the seeder is idempotent', function () {
    $this->seed(ReferenceDataSeeder::class);
    $first = counts();
    $ulids = IncidentType::query()->orderBy('id')->pluck('ulid')->all();

    $this->seed(ReferenceDataSeeder::class);

    expect(counts())->toBe($first)
        ->and(IncidentType::query()->orderBy('id')->pluck('ulid')->all())->toBe($ulids);
});

test('the seeder never overwrites an administrator\'s edits', function () {
    $this->seed(ReferenceDataSeeder::class);

    $kidnapping = IncidentType::query()->where('code', 'kidnapping')->sole();
    $kidnapping->forceFill([
        'name' => 'Abduction',
        'default_severity' => Severity::High,
        'details_schema' => [['key' => 'victims', 'label' => 'Victims', 'type' => 'integer', 'required' => false]],
        'schema_version' => 4,
        'sort_order' => 1,
    ])->save();
    ThreatType::query()->where('code', 'cultism')->sole()->forceFill(['is_active' => false])->save();
    Denomination::query()->where('code', 'baptist')->sole()->forceFill(['name' => 'Baptist Convention'])->save();

    $this->seed(ReferenceDataSeeder::class);

    $kidnapping->refresh();
    expect($kidnapping->name)->toBe('Abduction')
        ->and($kidnapping->default_severity)->toBe(Severity::High)
        ->and(array_column($kidnapping->details_schema, 'key'))->toBe(['victims'])
        ->and($kidnapping->schema_version)->toBe(4)
        ->and($kidnapping->sort_order)->toBe(1)
        ->and(ThreatType::query()->where('code', 'cultism')->sole()->is_active)->toBeFalse()
        ->and(Denomination::query()->where('code', 'baptist')->sole()->name)->toBe('Baptist Convention')
        ->and(IncidentType::query()->where('code', 'kidnapping')->count())->toBe(1);
});

test('the database seeder loads reference data before the hierarchy step', function () {
    // Outside `local`, and without the owner's LGA files, the hierarchy step
    // stops the seeder; reference data must already be in place by then.
    expect(fn () => $this->seed())->toThrow(RuntimeException::class, 'Cannot seed the administrative hierarchy');

    expect(counts()['incident_types'])->toBe(15);
});
