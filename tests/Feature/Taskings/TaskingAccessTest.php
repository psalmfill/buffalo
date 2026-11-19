<?php

use App\Actions\Reports\RequestReport;
use App\Actions\Taskings\Attachments\StoreTaskingAttachment;
use App\Actions\Taskings\RecordTaskingProgress;
use App\Actions\Taskings\SendTasking;
use App\Enums\Classification;
use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Models\Lga;
use App\Models\Person;
use App\Models\ReportRun;
use App\Models\Tasking;
use App\Models\TaskingAttachment;
use App\Models\TaskingNote;
use App\Models\TaskingRecipient;
use App\Models\TaskingResponse;
use App\Models\User;
use App\Support\Evidence\EvidenceFileInspector;
use App\Support\Exports\Lists\ListExporters;
use App\Support\Jurisdiction\Contracts\AccessGrantResolver;
use App\Support\Jurisdiction\Contracts\Disseminable;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Exports\ExportFiles;
use Tests\Support\Incidents\EvidenceFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Taskings\TaskingFixtures;

/*
| Phase 11H.2 rule 3 and 11H.6: who sees which tasking, on every route.
|
| Taskings: ALL (A1 → A1a and A1b, every LGA of A1), B (A1 → A1b only),
| A2 (A2 → A2) and B1 (B1 → B1). Each has a photo on the tasking; on ALL,
| A1a and A1b each completed with a report back and a photo.
|
| | User                         | Taskings          | Recipient rows                |
| |------------------------------|-------------------|-------------------------------|
| | LGA chief of A1a             | ALL               | A1a's only                    |
| | State Director, Deputy (A1)  | ALL, B            | all                           |
| | DDG of zone A                | ALL, B, A2        | all (read-only)               |
| | DDG (national), DG           | all four          | all (read-only)               |
| | Super admin                  | none              | none                          |
| | State Director of B1         | B1                | its own                       |
*/

const TASKING_REACH = [
    'lga_chief' => ['ALL'],
    'state_director' => ['ALL', 'B'],
    'deputy_state_director' => ['ALL', 'B'],
    'ddg_zone_a' => ['ALL', 'B', 'A2'],
    'ddg_national' => ['ALL', 'B', 'A2', 'B1'],
    'director_general' => ['ALL', 'B', 'A2', 'B1'],
    'super_admin' => [],
];

beforeEach(function () {
    Notification::fake();
    Storage::fake('evidence');
    Storage::fake('reports');
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $w = $this->world;
    $this->chiefA = TaskingFixtures::chief($w->lgas['A1a']);
    $this->chiefB = TaskingFixtures::chief($w->lgas['A1b']);
    $this->directorB1 = TaskingFixtures::director($w->states['B1']);
    $directorA2 = TaskingFixtures::director($w->states['A2']);
    $file = fn (UploadedFile $f) => [$f, (array) app(EvidenceFileInspector::class)->inspect((string) $f->getRealPath(), $f->getClientOriginalName())];

    $make = function (User $sender, array $lgas, string $key) use ($file): Tasking {
        $draft = TaskingFixtures::draft($sender, ['title' => "Secret-ish title {$key}", 'body' => "<p>Body of {$key}.</p>"]);
        [$photo, $detected] = $file(EvidenceFixtures::photo());
        $this->photos[$key] = app(JurisdictionContext::class)->actingAs($sender, fn () => app(StoreTaskingAttachment::class)($draft, null, $photo, $detected, "Photo {$key}", Classification::Restricted, $sender));
        $sent = app(JurisdictionContext::class)->actingAs($sender, fn () => app(SendTasking::class)($draft, array_map(fn (Lga $l) => $l->id, $lgas), $sender));
        CrossFixtures::system();

        return $sent;
    };

    $this->photos = [];
    $this->taskings = [
        'ALL' => $make($w->users['state_director'], [$w->lgas['A1a'], $w->lgas['A1b']], 'ALL'),
        'B' => $make($w->users['state_director'], [$w->lgas['A1b']], 'B'),
        'A2' => $make($directorA2, [$w->lgas['A2']], 'A2'),
        'B1' => $make($this->directorB1, [$w->lgas['B1']], 'B1'),
    ];

    // Both A1 LGAs report back on ALL, each with a photo.
    $this->rows = [];
    $this->responsePhotos = [];
    foreach (['A1a' => $this->chiefA, 'A1b' => $this->chiefB] as $key => $chief) {
        $row = TaskingRecipient::query()->where('tasking_id', $this->taskings['ALL']->id)->where('lga_id', $w->lgas[$key]->id)->sole();
        app(JurisdictionContext::class)->actingAs($chief, function () use ($row, $chief, $key, $file): void {
            app(RecordTaskingProgress::class)->complete($row, "<p>Report back from {$key}.</p>", $chief);
            app(RecordTaskingProgress::class)->respond($row->refresh(), "<p>Further from {$key}.</p>", $chief);
            [$photo, $detected] = $file(EvidenceFixtures::photo());
            $this->responsePhotos[$key] = app(StoreTaskingAttachment::class)($this->taskings['ALL'], $row, $photo, $detected, "Response {$key}", Classification::Restricted, $chief);
        });
        $this->rows[$key] = $row;
        CrossFixtures::system();
    }
});

test('each role sees exactly the taskings of its reach, on every route', function (string $role) {
    $user = $this->world->users[$role];
    $reach = TASKING_REACH[$role];
    $client = fn () => CrossFixtures::signIn($user);

    if ($role === 'super_admin') {
        $client()->get('/taskings')->assertForbidden();
    } else {
        $listed = collect($client()->get('/taskings?lens=off')->assertOk()->inertiaProps('taskings.data'))->pluck('ulid')->sort()->values()->all();
        expect($listed)->toBe(collect($reach)->map(fn (string $k) => $this->taskings[$k]->ulid)->sort()->values()->all());
    }

    foreach ($this->taskings as $key => $tasking) {
        $photo = $this->photos[$key];
        $inside = in_array($key, $reach, true);
        $refused = $role === 'super_admin' ? [403, 404] : [404];
        $expect = fn ($response) => $inside
            ? $response->assertOk()
            : expect(in_array($response->status(), $refused, true))->toBeTrue("{$role} reached {$key} ({$response->status()})");

        $expect($client()->get("/taskings/{$tasking->ulid}"));
        $expect($client()->get("/tasking-attachments/{$photo->ulid}/thumb"));
        $expect($client()->get("/tasking-attachments/{$photo->ulid}/preview"));
        $expect($client()->get("/tasking-attachments/{$photo->ulid}/download"));

        if (! $inside) {
            // Writes are refused before validation.
            expect([403, 404])->toContain($client()->post("/taskings/{$tasking->ulid}/recall", ['reason' => 'Not mine at all'])->status())
                ->and([403, 404])->toContain($client()->post("/taskings/{$tasking->ulid}/notes", ['body' => '<p>x</p>'])->status())
                ->and([403, 404])->toContain($client()->postJson("/taskings/{$tasking->ulid}/attachments", ['file' => EvidenceFixtures::photo()])->status());
        }
    }
})->with(array_keys(TASKING_REACH));

test('an A1a chief sees only A1a\'s row of an all-LGAs tasking: never the others or their responses', function () {
    $all = $this->taskings['ALL'];
    $props = CrossFixtures::signIn($this->chiefA)->get("/taskings/{$all->ulid}")->assertOk()->inertiaProps();

    expect($props['recipients'])->toBeNull()
        ->and($props['progress'])->toBeNull()
        ->and($props['tasking']['target_mode'])->toBeNull()
        ->and($props['mine']['lga']['name'])->toBe($this->world->lgas['A1a']->name)
        ->and($props['mine']['response'])->toContain('Report back from A1a');

    $text = json_encode($props);
    expect($text)->not->toContain('A1b')
        ->and($text)->not->toContain($this->rows['A1b']->ulid)
        ->and($text)->not->toContain($this->world->lgas['A1b']->name);

    // A1b's row, response and photo are out of reach: 404.
    $b = $this->rows['A1b'];
    CrossFixtures::signIn($this->chiefA)->get("/taskings/{$all->ulid}?recipient={$b->ulid}")->assertOk()->assertInertia(fn ($page) => $page->where('selected', null));
    CrossFixtures::signIn($this->chiefA)->post("/tasking-recipients/{$b->ulid}/responses", ['body' => '<p>x</p>'])->assertNotFound();
    CrossFixtures::signIn($this->chiefA)->get("/tasking-attachments/{$this->responsePhotos['A1b']->ulid}/thumb")->assertNotFound();
    CrossFixtures::signIn($this->chiefA)->get("/tasking-attachments/{$this->responsePhotos['A1a']->ulid}/download")->assertOk();

    // The scopes agree.
    app(JurisdictionContext::class)->actingAs($this->chiefA, function () use ($all): void {
        expect(TaskingRecipient::query()->where('tasking_id', $all->id)->pluck('lga_id')->all())->toBe([$this->world->lgas['A1a']->id])
            ->and(TaskingResponse::query()->pluck('lga_id')->unique()->values()->all())->toBe([$this->world->lgas['A1a']->id])
            ->and(TaskingAttachment::query()->pluck('ulid')->sort()->values()->all())
            ->toBe(collect([$this->photos['ALL']->ulid, $this->responsePhotos['A1a']->ulid])->sort()->values()->all());
    });

    // The export: own status only, never the target mode or other LGAs' counts.
    $exporter = ListExporters::find('taskings');
    $rows = app(JurisdictionContext::class)->actingAs($this->chiefA, function () use ($exporter) {
        $rows = $exporter->query($this->chiefA, [])->get();
        $exporter->prepare($rows, $this->chiefA);

        return $rows->map(fn (Tasking $t) => collect($exporter->columns())->mapWithKeys(fn ($c) => [$c->key => ($c->value)($t)])->all())->all();
    });
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['target'])->toBeNull()
        ->and($rows[0]['recipients'])->toBeNull()
        ->and($rows[0]['own_status'])->toBe('Completed');
});

test('the directors see every recipient and every response of their state\'s taskings', function () {
    $all = $this->taskings['ALL'];
    $props = CrossFixtures::signIn($this->world->users['deputy_state_director'])->get("/taskings/{$all->ulid}?recipient={$this->rows['A1b']->ulid}")->assertOk()->inertiaProps();

    expect(collect($props['recipients'])->pluck('lga.name')->sort()->values()->all())
        ->toBe(collect([$this->world->lgas['A1a']->name, $this->world->lgas['A1b']->name])->sort()->values()->all())
        ->and($props['selected']['response'])->toContain('Report back from A1b')
        ->and($props['selected']['attachments'])->toHaveCount(1)
        ->and($props['progress']['line'])->toBe('2 of 2 read · 2 acknowledged · 2 completed');
});

test('another state\'s director and chief never see these taskings', function () {
    foreach (['ALL', 'B', 'A2'] as $key) {
        CrossFixtures::signIn($this->directorB1)->get("/taskings/{$this->taskings[$key]->ulid}")->assertNotFound();
    }
    $chiefB1 = TaskingFixtures::chief($this->world->lgas['B1']);
    CrossFixtures::signIn($chiefB1)->get("/taskings/{$this->taskings['ALL']->ulid}")->assertNotFound();
    expect(collect(CrossFixtures::signIn($chiefB1)->get('/taskings')->inertiaProps('taskings.data'))->pluck('ulid')->all())
        ->toBe([$this->taskings['B1']->ulid]);
});

test('the LGA chief of A1b sees ALL and B; a chief sees only sent taskings', function () {
    expect(collect(CrossFixtures::signIn($this->chiefB)->get('/taskings')->inertiaProps('taskings.data'))->pluck('ulid')->sort()->values()->all())
        ->toBe(collect([$this->taskings['ALL']->ulid, $this->taskings['B']->ulid])->sort()->values()->all());

    $draft = TaskingFixtures::draft($this->world->users['state_director'], ['draft_lga_ids' => [$this->world->lgas['A1b']->id]]);
    CrossFixtures::signIn($this->chiefB)->get("/taskings/{$draft->ulid}")->assertNotFound();
});

test('a reposted chief\'s successor sees the LGA\'s taskings; the old chief no longer does', function () {
    $all = $this->taskings['ALL'];
    // The A1a chief moves to B1.
    $this->chiefA->forceFill(['state_id' => $this->world->states['B1']->id, 'lga_id' => $this->world->lgas['B1']->id])->saveQuietly();
    CrossFixtures::signIn($this->chiefA)->get("/taskings/{$all->ulid}")->assertNotFound();

    $successor = TaskingFixtures::chief($this->world->lgas['A1a']);
    $props = CrossFixtures::signIn($successor)->get("/taskings/{$all->ulid}")->assertOk()->inertiaProps();
    expect($props['mine']['response'])->toContain('Report back from A1a');
});

test('dashboards: the director\'s card shows own state only; the chief\'s inbox own LGA only', function () {
    $card = CrossFixtures::signIn($this->world->users['state_director'])->get('/dashboard')->inertiaProps('taskings');
    expect(collect($card['latest'])->pluck('ulid')->sort()->values()->all())
        ->toBe(collect([$this->taskings['ALL']->ulid, $this->taskings['B']->ulid])->sort()->values()->all());

    $inbox = CrossFixtures::signIn($this->chiefA)->get('/dashboard')->inertiaProps('taskingInbox');
    expect(collect($inbox['latest'])->pluck('ulid')->all())->toBe([$this->taskings['ALL']->ulid])
        ->and($inbox['latest'][0]['progress'])->toBeNull();

    // National dashboards carry no tasking card.
    expect(CrossFixtures::signIn($this->world->users['director_general'])->get('/dashboard?lens=off')->inertiaProps('taskings'))->toBeNull();
});

test('the unread badge counts an LGA chief\'s unread taskings', function () {
    $chief = TaskingFixtures::chief($this->world->lgas['A1b']);
    // B is unread by A1b (ALL was completed).
    expect(CrossFixtures::signIn($chief)->get('/taskings')->inertiaProps('badges.taskings'))->toBe(1);
    CrossFixtures::signIn($chief)->get("/taskings/{$this->taskings['B']->ulid}");
    expect(CrossFixtures::signIn($chief)->get('/taskings')->inertiaProps('badges.taskings'))->toBe(0);
});

test('the PDF is built only within reach, and an LGA chief\'s copy carries only their LGA', function () {
    $request = fn (User $user, string $key) => app(JurisdictionContext::class)->actingAs($user, fn () => app(RequestReport::class)(
        $user, ReportType::Tasking, ReportFormat::Pdf, ['tasking' => $this->taskings[$key]->ulid], stepUpConfirmed: false,
    ));

    expect(fn () => $request($this->world->users['lga_chief'], 'B'))->toThrow(ValidationException::class)
        ->and(fn () => $request($this->world->users['state_director'], 'A2'))->toThrow(ValidationException::class)
        ->and(fn () => $request($this->directorB1, 'ALL'))->toThrow(ValidationException::class)
        ->and($this->world->users['super_admin']->can('generate', [ReportRun::class, ReportType::Tasking]))->toBeFalse();

    // The chief's copy: their own LGA's report back, no recipients table.
    $run = $request($this->chiefA, 'ALL')->refresh();
    expect($run->status->value.' '.$run->error_message)->toBe('completed ');
    $chiefPdf = ExportFiles::pdfText($run);
    expect($chiefPdf)->toContain((string) $this->taskings['ALL']->reference_no)
        ->toContain('Report back from A1a')
        ->not->toContain('Report back from A1b')
        ->not->toContain('Recipients (');

    // The director's copy: the recipients table.
    $directorPdf = ExportFiles::pdfText($request($this->world->users['state_director'], 'ALL')->refresh());
    expect($directorPdf)->toContain('Recipients (2)')
        ->toContain('2 of 2 read');
});

test('global search never returns taskings (no provider), so titles cannot leak', function () {
    $hits = CrossFixtures::signIn($this->chiefA)->getJson('/search/quick?q='.urlencode('Secret-ish title B1'))->assertOk()->json();
    $text = (string) json_encode($hits);

    foreach ($this->taskings as $tasking) {
        expect($text)->not->toContain($tasking->ulid);
    }
    expect($text)->not->toContain('/taskings/');
});

test('taskings, notes, responses and attachments are neither grantable nor disseminable', function () {
    $chief = $this->world->users['lga_chief'];
    $resolver = app(AccessGrantResolver::class);
    CrossFixtures::system();
    $note = new TaskingNote;

    foreach ([$this->taskings['B1'], $this->photos['B1'], $this->rows['A1b'], $note] as $record) {
        expect($record)->not->toBeInstanceOf(Disseminable::class)
            ->and($record)->not->toBeInstanceOf(Person::class);
    }
    expect($resolver->hasGrant($chief, $this->taskings['B1']))->toBeFalse();
});
