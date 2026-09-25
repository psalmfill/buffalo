<?php

use App\Enums\AgencyType;
use App\Models\Denomination;
use App\Models\IncidentType;
use App\Models\Religion;
use App\Models\SecurityAgency;
use App\Models\State;
use App\Models\ThreatType;
use App\Models\User;
use App\Rules\ActiveOption;
use App\Support\ReferenceData\Contracts\ReferenceUsage;
use App\Support\ReferenceData\ReferenceData;
use App\Support\ReferenceData\ReferenceTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Jurisdiction\JurisdictionWorld;

const MANAGERS = ['super_admin', 'director_general'];
const VIEWERS = ['ddg_national', 'ddg_zone_a', 'state_director', 'deputy_state_director', 'lga_chief'];

/** Marks every incident type as used by records (Phase 5 will do this for real). */
function incidentTypesInUse(): void
{
    app()->instance(ReferenceUsage::class, new class implements ReferenceUsage
    {
        public function isInUse(Model $value): bool
        {
            return $value instanceof IncidentType;
        }
    });
}

test('every role can view reference data; a user without a role cannot', function () {
    $world = JurisdictionWorld::build();
    $type = IncidentType::factory()->create();

    foreach ([...MANAGERS, ...VIEWERS] as $who) {
        $this->actingAs($world->users[$who]);
        $this->get('/reference-data')->assertRedirect('/reference-data/incident-types');

        foreach (ReferenceTable::cases() as $table) {
            $this->get("/reference-data/{$table->value}")->assertOk();
        }

        $this->get("/reference-data/incident-types/{$type->ulid}")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('reference-data/show')->has('schema'));
        $this->flushSession();
    }

    $this->actingAs($world->users['no_role'])->get('/reference-data/incident-types')->assertForbidden();
});

test('only the super admin and the DG may manage reference data', function (string $who, bool $allowed) {
    $world = JurisdictionWorld::build();
    $type = IncidentType::factory()->create();
    $this->actingAs($world->users[$who]);

    $requests = [
        ['get', '/reference-data/threat-types/create', []],
        ['post', '/reference-data/threat-types', ['name' => 'Arson', 'code' => 'arson_'.$who, 'category' => 'crime']],
        ['get', "/reference-data/incident-types/{$type->ulid}/edit", []],
        ['put', "/reference-data/incident-types/{$type->ulid}", ['name' => 'Renamed', 'category' => 'crime', 'default_severity' => 'high']],
        ['post', "/reference-data/incident-types/{$type->ulid}/deactivate", []],
        ['post', "/reference-data/incident-types/{$type->ulid}/activate", []],
        ['put', "/reference-data/incident-types/{$type->ulid}/schema", ['schema' => [['key' => 'a', 'label' => 'A', 'type' => 'text']]]],
    ];

    foreach ($requests as [$method, $uri, $data]) {
        $status = $this->{$method}($uri, $data)->status();

        $allowed
            ? expect($status)->toBeLessThan(400, "{$who}: {$method} {$uri} gave {$status}")
            : expect($status)->toBe(403, "{$who}: {$method} {$uri} gave {$status}");
    }

    $this->get('/reference-data/incident-types')->assertInertia(fn (Assert $page) => $page->where('can.manage', $allowed));
})->with([
    ['super_admin', true],
    ['director_general', true],
    ['ddg_national', false],
    ['state_director', false],
    ['deputy_state_director', false],
    ['lga_chief', false],
]);

test('an LGA chief sees reference data read-only', function () {
    $world = JurisdictionWorld::build();
    $type = IncidentType::factory()->create();

    $this->actingAs($world->users['lga_chief'])->get('/reference-data/incident-types')
        ->assertInertia(fn (Assert $page) => $page->where('can.manage', false)->has('values', 1));
    $this->actingAs($world->users['lga_chief'])->get("/reference-data/incident-types/{$type->ulid}")
        ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));
});

test('unknown tables are 404', function () {
    $this->actingAs(User::factory()->superAdmin()->create())->get('/reference-data/weapons')->assertNotFound();
});

test('the list filters by status and search and exposes no numeric ids', function () {
    $admin = User::factory()->superAdmin()->create();
    ThreatType::factory()->create(['name' => 'Arson', 'code' => 'arson']);
    ThreatType::factory()->inactive()->create(['name' => 'Smuggling', 'code' => 'smuggling']);

    $response = $this->actingAs($admin)->get('/reference-data/threat-types');
    expect(json_encode($response->viewData('page')['props']['values']))->not->toContain('"id"');

    $this->get('/reference-data/threat-types?status=inactive')->assertInertia(fn (Assert $page) => $page->has('values', 1)->where('values.0.code', 'smuggling'));
    $this->get('/reference-data/threat-types?search=ARS')->assertInertia(fn (Assert $page) => $page->has('values', 1)->where('values.0.code', 'arson'));
});

test('creating a value validates the code and records the author', function () {
    $admin = User::factory()->superAdmin()->create();
    ThreatType::factory()->create(['code' => 'arson']);
    $this->actingAs($admin);

    $this->post('/reference-data/threat-types', ['name' => 'X', 'code' => 'Bad Code', 'category' => 'crime'])->assertSessionHasErrors('code');
    $this->post('/reference-data/threat-types', ['name' => 'X', 'code' => 'arson', 'category' => 'crime'])->assertSessionHasErrors('code');
    $this->post('/reference-data/threat-types', ['name' => 'X', 'code' => 'poaching', 'category' => 'weather'])->assertSessionHasErrors('category');
    $this->post('/reference-data/threat-types', ['name' => 'Poaching', 'code' => 'poaching', 'category' => 'crime'])
        ->assertRedirect('/reference-data/threat-types');

    $poaching = ThreatType::query()->where('code', 'poaching')->sole();
    expect($poaching->created_by)->toBe($admin->id)->and($poaching->is_active)->toBeTrue();
});

test('a new incident type goes straight to its detail fields', function () {
    $this->actingAs(User::factory()->directorGeneral()->create())
        ->post('/reference-data/incident-types', ['name' => 'Arson', 'code' => 'arson', 'category' => 'crime', 'default_severity' => 'high'])
        ->assertRedirect('/reference-data/incident-types/'.IncidentType::query()->where('code', 'arson')->value('ulid').'/edit');
});

test('codes are fixed once created', function () {
    $type = ThreatType::factory()->create(['code' => 'arson']);

    $this->actingAs(User::factory()->superAdmin()->create())
        ->put("/reference-data/threat-types/{$type->ulid}", ['name' => 'Arson', 'code' => 'fire', 'category' => 'crime'])
        ->assertSessionHasErrors('code');

    expect(fn () => $type->forceFill(['code' => 'fire'])->save())->toThrow(LogicException::class);
});

test('denomination codes are unique within a religion, and the religion is fixed', function () {
    $admin = User::factory()->superAdmin()->create();
    $christianity = Religion::factory()->create();
    $islam = Religion::factory()->create();
    $other = Denomination::factory()->for($christianity)->create(['code' => 'other_group']);
    $this->actingAs($admin);

    $this->post('/reference-data/denominations', ['name' => 'Other', 'code' => 'other_group', 'religion' => $christianity->ulid])->assertSessionHasErrors('code');
    $this->post('/reference-data/denominations', ['name' => 'Other', 'code' => 'other_group', 'religion' => $islam->ulid])->assertSessionDoesntHaveErrors();

    $this->put("/reference-data/denominations/{$other->ulid}", ['name' => 'Other', 'religion' => $islam->ulid])->assertSessionHasErrors('religion');
});

test('only state outfits belong to a state', function () {
    $this->actingAs(User::factory()->superAdmin()->create());
    $state = State::factory()->create();

    $this->post('/reference-data/security-agencies', ['name' => 'Amotekun', 'code' => 'amotekun', 'short_name' => 'AMO', 'agency_type' => 'state'])->assertSessionHasErrors('state');
    $this->post('/reference-data/security-agencies', ['name' => 'Vigilante', 'code' => 'vigilante', 'short_name' => 'VG', 'agency_type' => 'community', 'state' => $state->ulid])->assertSessionHasErrors('state');
    $this->post('/reference-data/security-agencies', ['name' => 'Amotekun', 'code' => 'amotekun', 'short_name' => 'AMO', 'agency_type' => 'state', 'state' => $state->ulid])->assertSessionDoesntHaveErrors();

    expect(SecurityAgency::query()->where('code', 'amotekun')->sole()->state_id)->toBe($state->id);

    // The database agrees.
    expect(fn () => DB::transaction(fn () => SecurityAgency::factory()->create(['agency_type' => AgencyType::State, 'state_id' => null])))->toThrow(QueryException::class);
});

test('deactivated values cannot be chosen for new selections but stay on existing records', function () {
    $this->actingAs(User::factory()->superAdmin()->create());
    $retired = Religion::factory()->inactive()->create();
    $inactiveState = State::factory()->inactive()->create();

    $this->post('/reference-data/denominations', ['name' => 'X', 'code' => 'x_group', 'religion' => $retired->ulid])
        ->assertSessionHasErrors(['religion' => 'The selected religion is no longer available.']);

    // An agency already in an inactive state can still be edited.
    $agency = SecurityAgency::factory()->create(['agency_type' => AgencyType::State, 'state_id' => $inactiveState->id]);
    $this->put("/reference-data/security-agencies/{$agency->ulid}", ['name' => 'Renamed', 'short_name' => 'RN', 'agency_type' => 'state', 'state' => $inactiveState->ulid])
        ->assertSessionDoesntHaveErrors();
    expect($agency->fresh()->name)->toBe('Renamed');

    // The rule, directly.
    $rule = fn (?string $keep) => Validator::make(['religion' => $retired->ulid], ['religion' => [new ActiveOption(Religion::class, $keep)]])->passes();
    expect($rule(null))->toBeFalse()->and($rule($retired->ulid))->toBeTrue();

    // Display still resolves it; form options leave it out.
    $data = app(ReferenceData::class);
    expect($data->find(ReferenceTable::Religions, $retired->ulid)?->name)->toBe($retired->name)
        ->and(collect($data->options(ReferenceTable::Religions))->pluck('ulid'))->not->toContain($retired->ulid);
});

test('activating and deactivating are audited', function () {
    $admin = User::factory()->superAdmin()->create();
    $type = ThreatType::factory()->create();
    $this->actingAs($admin);

    $this->post("/reference-data/threat-types/{$type->ulid}/deactivate")->assertRedirect();
    expect($type->fresh()->is_active)->toBeFalse();

    $this->post("/reference-data/threat-types/{$type->ulid}/activate")->assertRedirect();
    expect($type->fresh()->is_active)->toBeTrue();

    $changes = Activity::query()->where('subject_type', ThreatType::class)->where('subject_id', $type->id)->where('event', 'updated')->get();
    expect($changes)->toHaveCount(2)
        ->and($changes[0]->causer_id)->toBe($admin->id)
        ->and($changes[0]->attribute_changes['attributes']['is_active'])->toBeFalse();
});

test('reference data can never be deleted', function () {
    $this->actingAs(User::factory()->superAdmin()->create());
    $type = IncidentType::factory()->create();

    $this->delete("/reference-data/incident-types/{$type->ulid}")->assertStatus(405);
    expect(fn () => $type->delete())->toThrow(LogicException::class, 'deactivated, never deleted');

    // Not even with a query that skips model events (one row per table, so
    // the row trigger has something to refuse).
    foreach (ReferenceTable::cases() as $table) {
        $table->modelClass()::factory()->create();
        expect(fn () => DB::transaction(fn () => DB::table($table->tableName())->delete()))->toThrow(QueryException::class);
    }

    expect($type->fresh())->not->toBeNull();
});

test('form options are cached and refreshed when a value changes', function () {
    $data = app(ReferenceData::class);
    $type = ThreatType::factory()->create(['name' => 'Arson', 'sort_order' => 1]);
    ThreatType::factory()->create(['name' => 'Banditry', 'sort_order' => 2]);

    expect(collect($data->options(ReferenceTable::ThreatTypes))->pluck('name')->all())->toBe(['Arson', 'Banditry'])
        ->and(Cache::has('reference_data:options:threat-types'))->toBeTrue();

    // Stale reads are impossible: every save clears the cache.
    $this->actingAs(User::factory()->superAdmin()->create())
        ->put("/reference-data/threat-types/{$type->ulid}", ['name' => 'Fire-setting', 'category' => 'crime', 'sort_order' => 3]);
    expect(Cache::has('reference_data:options:threat-types'))->toBeFalse()
        ->and(collect($data->options(ReferenceTable::ThreatTypes))->pluck('name')->all())->toBe(['Banditry', 'Fire-setting']);

    $this->post("/reference-data/threat-types/{$type->ulid}/deactivate");
    expect(collect($data->options(ReferenceTable::ThreatTypes))->pluck('name')->all())->toBe(['Banditry']);

    // Other tables' caches are untouched.
    $data->options(ReferenceTable::Religions);
    $type->forceFill(['name' => 'Again'])->save();
    expect(Cache::has('reference_data:options:religions'))->toBeTrue();
});

test('options carry ULIDs and extras, never numeric ids', function () {
    $religion = Religion::factory()->create();
    Denomination::factory()->for($religion)->create();
    IncidentType::factory()->create(['details_schema' => [['key' => 'a', 'label' => 'A', 'type' => 'text']]]);

    $options = app(ReferenceData::class);
    $denomination = $options->options(ReferenceTable::Denominations)[0];
    $incident = $options->options(ReferenceTable::IncidentTypes)[0];

    expect($denomination)->toHaveKeys(['ulid', 'name', 'code', 'religion'])->not->toHaveKey('id')
        ->and($denomination['religion'])->toBe($religion->ulid)
        ->and($incident)->toHaveKeys(['category', 'default_severity', 'details_schema', 'schema_version']);
});

test('saving detail fields validates them and increments the version', function () {
    $this->actingAs(User::factory()->directorGeneral()->create());
    $type = IncidentType::factory()->create();
    $schema = [
        ['key' => 'victims', 'label' => 'Victims', 'type' => 'integer', 'min' => 0],
        ['key' => 'location', 'label' => 'Location', 'type' => 'select', 'options' => [['value' => 'home', 'label' => 'Home']]],
    ];

    $this->putJson("/reference-data/incident-types/{$type->ulid}/schema", ['schema' => [['key' => 'Bad Key', 'label' => 'X', 'type' => 'money']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['schema.0.key', 'schema.0.type']);
    expect($type->fresh()->schema_version)->toBe(1);

    $this->put("/reference-data/incident-types/{$type->ulid}/schema", ['schema' => $schema])->assertSessionHasNoErrors();
    $type->refresh();
    expect($type->schema_version)->toBe(2)
        // jsonb does not keep key order, so compare contents.
        ->and($type->details_schema[0])->toEqual(['key' => 'victims', 'label' => 'Victims', 'type' => 'integer', 'required' => false, 'min' => 0]);

    // Saving the same fields again is not a change.
    $this->put("/reference-data/incident-types/{$type->ulid}/schema", ['schema' => $schema]);
    expect($type->fresh()->schema_version)->toBe(2);

    // A type not yet in use may still rename keys and change types.
    $this->put("/reference-data/incident-types/{$type->ulid}/schema", ['schema' => [['key' => 'victim_count', 'label' => 'Victims', 'type' => 'number']]])->assertSessionHasNoErrors();
    expect($type->fresh()->schema_version)->toBe(3);
});

test('an in-use type only accepts compatible changes, with a clear message', function () {
    incidentTypesInUse();
    $this->actingAs(User::factory()->directorGeneral()->create());
    $type = IncidentType::factory()->create(['details_schema' => [
        ['key' => 'victims', 'label' => 'Victims', 'type' => 'integer'],
        ['key' => 'location', 'label' => 'Location', 'type' => 'select', 'options' => [['value' => 'home', 'label' => 'Home'], ['value' => 'farm', 'label' => 'Farm']]],
    ]]);
    $url = "/reference-data/incident-types/{$type->ulid}/schema";

    $this->get("/reference-data/incident-types/{$type->ulid}/edit")->assertInertia(fn (Assert $page) => $page->where('schema.inUse', true));

    $this->putJson($url, ['schema' => [
        ['key' => 'victim_count', 'label' => 'Victims', 'type' => 'integer'],
        $type->details_schema[1],
    ]])->assertUnprocessable()->assertJsonPath('errors.schema.0', 'The field "victims" cannot be removed or renamed because this incident type is in use. Deprecate it instead.');

    $this->putJson($url, ['schema' => [
        ['key' => 'victims', 'label' => 'Victims', 'type' => 'text'],
        $type->details_schema[1],
    ]])->assertUnprocessable()->assertJsonPath('errors', ['schema.0.type' => ['The type of "victims" cannot change from integer to text because this incident type is in use.']]);

    $this->putJson($url, ['schema' => [
        $type->details_schema[0],
        ['key' => 'location', 'label' => 'Location', 'type' => 'select', 'options' => [['value' => 'home', 'label' => 'Home']]],
    ]])->assertUnprocessable()->assertJsonValidationErrors('schema.1.options');

    expect($type->fresh()->schema_version)->toBe(1);

    // Compatible: reorder, relabel, deprecate, add a field and an option.
    $this->put($url, ['schema' => [
        ['key' => 'location', 'label' => 'Where', 'type' => 'select', 'options' => [
            ['value' => 'home', 'label' => 'Home', 'deprecated' => true],
            ['value' => 'farm', 'label' => 'Farm'],
            ['value' => 'school', 'label' => 'School'],
        ]],
        ['key' => 'victims', 'label' => 'Victims', 'type' => 'integer', 'deprecated' => true],
        ['key' => 'ransom', 'label' => 'Ransom', 'type' => 'number'],
    ]])->assertSessionHasNoErrors();

    expect($type->fresh()->schema_version)->toBe(2)
        ->and(array_column($type->fresh()->details_schema, 'key'))->toBe(['location', 'victims', 'ransom']);
});

test('schema changes are audited', function () {
    $dg = User::factory()->directorGeneral()->create();
    $type = IncidentType::factory()->create();

    $this->actingAs($dg)->put("/reference-data/incident-types/{$type->ulid}/schema", ['schema' => [['key' => 'a', 'label' => 'A', 'type' => 'text']]]);

    $entry = Activity::query()->where('subject_id', $type->id)->where('subject_type', IncidentType::class)->where('event', 'updated')->sole();
    expect($entry->causer_id)->toBe($dg->id)
        ->and($entry->attribute_changes['attributes'])->toHaveKeys(['details_schema', 'schema_version']);
});
