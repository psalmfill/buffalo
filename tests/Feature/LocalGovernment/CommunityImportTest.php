<?php

use App\Actions\LocalGovernment\ImportCommunities;
use App\Jobs\ImportCommunitiesJob;
use App\Models\Community;
use App\Models\Lga;
use App\Models\Ward;
use App\Notifications\CommunityImportFinished;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\LocalGovernment\CommunityCsv;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Jurisdiction\JurisdictionWorld;

function csvFile(array $lines, string $header = 'name,type,ward_code,latitude,longitude,population_estimate,other_names,notes'): UploadedFile
{
    return UploadedFile::fake()->createWithContent('communities.csv', "\u{FEFF}".$header."\n".implode("\n", $lines)."\n");
}

/**
 * @return array{0: JurisdictionWorld, 1: Lga}
 */
function importWorld(): array
{
    $world = JurisdictionWorld::build();
    $lga = $world->lgas['A1a'];
    Community::factory()->in($lga)->create(['name' => 'Ajegunle']);
    Community::factory()->in($lga)->create(['name' => 'New Town', 'other_names' => ['Oke Oyingbo']]);
    Ward::factory()->create(['lga_id' => $lga->id, 'state_id' => $lga->state_id, 'code' => 'W01']);

    return [$world, $lga];
}

const SAMPLE_ROWS = [
    'Alpha Village,village,W01,,,500,Alpha|Alfa,Near the river',   // 2 new
    'Ajegunlle,town,,,,,,',                                         // 3 possible duplicate of Ajegunle
    'Beta,hamlet,,,,,,',                                            // 4 error: type
    'AJEGUNLE,town,,,,,,',                                          // 5 error: name exists
    'Gamma,village,,6.2,,,,',                                       // 6 error: latitude without longitude
    'Delta,village,W99,,,,,',                                       // 7 error: unknown ward
    'alpha village,village,,,,,,',                                  // 8 error: repeated in file
    'Oke-Oyingbo,settlement,,,,,,',                                 // 9 possible duplicate of New Town's other name
    'Epsilon,waterside,,6.45,3.39,,,',                              // 10 new, with a location
];

test('the template is the header only', function () {
    [$world] = importWorld();

    $response = $this->actingAs($world->users['state_director'])->get('/communities/import/template')->assertOk();

    expect($response->getContent())->toBe("name,type,ward_code,latitude,longitude,population_estimate,other_names,notes\n")
        ->and($response->headers->get('Content-Disposition'))->toContain('communities-template.csv');
});

test('only DG, state directors and deputies may import', function (string $who, int $status) {
    [$world] = importWorld();

    $this->actingAs($world->users[$who])->get('/communities/import')->assertStatus($status);
})->with([
    ['director_general', 200],
    ['state_director', 200],
    ['deputy_state_director', 200],
    ['ddg_national', 403],
    ['lga_chief', 403],
    ['super_admin', 403],
]);

test('the preview classifies every row as new, possible duplicate or error', function () {
    [$world, $lga] = importWorld();
    Storage::fake('local');

    $response = $this->actingAs($world->users['state_director'])
        ->post('/communities/import/preview', ['lga' => $lga->ulid, 'file' => csvFile(SAMPLE_ROWS)])
        ->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('communities/import-preview')
        ->where('summary', ['new' => 2, 'duplicate' => 2, 'error' => 5])
        ->where('rows', function ($rows) {
            $byLine = collect($rows)->keyBy('line');

            return $byLine->map(fn ($row) => $row['status'])->all() === [
                2 => 'new', 3 => 'duplicate', 4 => 'error', 5 => 'error', 6 => 'error',
                7 => 'error', 8 => 'error', 9 => 'duplicate', 10 => 'new',
            ]
                && $byLine[3]['match']['name'] === 'Ajegunle'
                && $byLine[3]['match']['similarity'] >= 0.6
                && $byLine[9]['match']['name'] === 'New Town'
                && $byLine[2]['other_names'] === ['Alpha', 'Alfa']
                && str_contains($byLine[4]['errors'][0], 'type')
                && str_contains($byLine[5]['errors'][0], 'already exists')
                && str_contains($byLine[7]['errors'][0], 'W99')
                && str_contains($byLine[8]['errors'][0], 'repeated in this file (line 2)');
        }));

    // Nothing imported, nothing stored, no numeric ids sent.
    app(JurisdictionContext::class)->system();
    expect(Community::query()->count())->toBe(2)
        ->and(Storage::disk('local')->allFiles())->toBe([])
        ->and(json_encode($response->viewData('page')['props']['rows']))->not->toContain('ward_id');
});

test('the file must have exactly the expected columns', function (string $header) {
    [$world, $lga] = importWorld();

    $this->actingAs($world->users['state_director'])
        ->post('/communities/import/preview', ['lga' => $lga->ulid, 'file' => csvFile(['X,village,,,,,,'], $header)])
        ->assertSessionHasErrors('file');
})->with([
    'missing a column' => ['name,type,ward_code,latitude,longitude,population_estimate,other_names'],
    'an unknown column' => ['name,type,ward_code,latitude,longitude,population_estimate,other_names,notes,chief'],
]);

test('confirming imports the new rows and only the ticked duplicates', function () {
    [$world, $lga] = importWorld();
    $director = $world->users['state_director'];

    $preview = $this->actingAs($director)->post('/communities/import/preview', ['lga' => $lga->ulid, 'file' => csvFile(SAMPLE_ROWS)]);
    $rows = $preview->viewData('page')['props']['rows'];

    // The client cannot promote an error row: rows are re-classified.
    $rows[2]['status'] = 'new';

    $this->post('/communities/import', ['lga' => $lga->ulid, 'rows' => $rows, 'include' => [3]])
        ->assertRedirect("/lgas/{$lga->ulid}?tab=communities")
        ->assertSessionHas('toast', fn ($toast) => str_starts_with($toast['message'], '3 communities imported (1 skipped, 5 with errors)'));

    app(JurisdictionContext::class)->system();
    $names = Community::query()->where('lga_id', $lga->id)->orderBy('name')->pluck('name')->all();
    expect($names)->toBe(['Ajegunle', 'Ajegunlle', 'Alpha Village', 'Epsilon', 'New Town']);

    $alpha = Community::query()->with('ward')->where('name', 'Alpha Village')->sole();
    $epsilon = Community::query()->withCoordinates()->where('name', 'Epsilon')->sole();
    expect($alpha->ward?->code)->toBe('W01')
        ->and($alpha->population_estimate)->toBe(500)
        ->and($alpha->created_by)->toBe($director->id)
        ->and($epsilon->latitude)->toBe(6.45);
});

test('an import is all or nothing', function () {
    [$world, $lga] = importWorld();
    Community::creating(function (Community $community) {
        if ($community->name === 'Epsilon') {
            throw new RuntimeException('Simulated failure');
        }
    });

    $rows = array_map(fn (string $line, int $i) => ['line' => $i + 2, ...array_combine(CommunityCsv::HEADER, str_getcsv($line))], SAMPLE_ROWS, array_keys(SAMPLE_ROWS));

    $context = app(JurisdictionContext::class);
    expect(fn () => $context->actingAs($world->users['state_director'], fn () => app(ImportCommunities::class)($lga, $rows, [3, 9], $world->users['state_director'])))
        ->toThrow(RuntimeException::class, 'Simulated failure');

    $context->system();
    expect(Community::query()->where('lga_id', $lga->id)->count())->toBe(2);
});

test('the chosen LGA must be within the importer\'s jurisdiction', function () {
    [$world] = importWorld();
    $other = $world->lgas['B1'];
    $this->actingAs($world->users['state_director']);

    $this->post('/communities/import/preview', ['lga' => $other->ulid, 'file' => csvFile(['Zeta,village,,,,,,'])])
        ->assertSessionHasErrors(['lga' => 'Choose an LGA within your jurisdiction.']);
    $this->post('/communities/import', ['lga' => $other->ulid, 'rows' => [['line' => 2, 'name' => 'Zeta', 'type' => 'village']], 'include' => []])
        ->assertSessionHasErrors('lga');

    app(JurisdictionContext::class)->system();
    expect(Community::query()->where('lga_id', $other->id)->exists())->toBeFalse();
});

test('large imports run on the queue, encrypted, in the user\'s context, and notify when done', function () {
    [$world, $lga] = importWorld();
    $director = $world->users['state_director'];
    Queue::fake();
    Notification::fake();

    $rows = array_map(fn (int $i) => ['line' => $i + 2, 'name' => "Settlement {$i}", 'type' => 'settlement'], range(1, 201));

    $this->actingAs($director)->post('/communities/import', ['lga' => $lga->ulid, 'rows' => $rows, 'include' => []])
        ->assertRedirect()
        ->assertSessionHas('toast', fn ($toast) => str_contains($toast['message'], 'background'));

    $job = null;
    Queue::assertPushed(ImportCommunitiesJob::class, function (ImportCommunitiesJob $pushed) use (&$job, $director) {
        $job = $pushed;

        return $pushed->userId === $director->id && count($pushed->rows) === 201;
    });
    expect($job)->toBeInstanceOf(ShouldBeEncrypted::class);

    // Workers start in system context; the job acts as the importer.
    app(JurisdictionContext::class)->system();
    app()->call([$job, 'handle']);

    expect(Community::query()->where('lga_id', $lga->id)->count())->toBe(203);
    Notification::assertSentTo($director, CommunityImportFinished::class, fn ($n) => $n->result['imported'] === 201);

    $job->failed(new RuntimeException('x'));
    Notification::assertSentTo($director, CommunityImportFinished::class, fn ($n) => $n->result === null);
});
