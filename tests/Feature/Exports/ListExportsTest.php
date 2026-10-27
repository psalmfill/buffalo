<?php

use App\Actions\Reports\RequestReport;
use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\ReportFormat;
use App\Enums\ReportRunStatus;
use App\Enums\ReportType;
use App\Exceptions\ExportQuotaExceeded;
use App\Exceptions\StepUpRequired;
use App\Jobs\GenerateReport;
use App\Models\Arrest;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Lga;
use App\Models\OrganizationSite;
use App\Models\RecordAccessLog;
use App\Models\ReportRun;
use App\Models\User;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Exports\Lists\ListExporters;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Exports\ExportFiles;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Security\MatrixRecords;

/*
| Phase 11E.2: list exports. One record of every routed model in LGA A1a
| (inside the SD's state A1) and another set in B1 (another zone).
*/

beforeEach(function () {
    Notification::fake();
    Storage::fake('reports');
    Storage::fake('evidence');
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->dg = $this->world->users['director_general'];
    $this->sd = $this->world->users['state_director'];
    $this->dsd = $this->world->users['deputy_state_director'];
    $this->chief = $this->world->users['lga_chief'];
    $this->inside = MatrixRecords::build($this->world->lgas['A1a'], $this->sd);
    $this->outside = MatrixRecords::build($this->world->lgas['B1'], CrossFixtures::directorOfB1($this->world));
    CrossFixtures::system();
});

/**
 * Requests and (synchronously) generates a list export as $user.
 *
 * @param  array<string, mixed>  $filters
 * @param  list<string>  $columns
 */
function exportList(User $user, string $list, array $filters = [], ReportFormat $format = ReportFormat::Csv, array $columns = [], ?string $reason = null, bool $stepUp = true): ReportRun
{
    $run = app(JurisdictionContext::class)->actingAs($user, fn () => app(RequestReport::class)(
        $user, ReportType::ListExport, $format, ['list' => $list, 'filters' => $filters, 'columns' => $columns, 'reason' => $reason], stepUpConfirmed: $stepUp,
    ));

    return $run->refresh();
}

/** Filters each tab exporter needs: the parent record from a matrix set. */
function exportFilters(string $list, MatrixRecords $set): array
{
    return match ($list) {
        'religious_institutions', 'vulnerable_population', 'security_notes', 'political_offices' => ['lga' => $set->get(Lga::class)?->getRouteKey()],
        'organization_contacts' => ['site' => $set->get(OrganizationSite::class)?->getRouteKey()],
        'criminal_group_members' => ['group' => $set->get(CriminalGroup::class)?->getRouteKey()],
        default => [],
    };
}

/** @return list<string> */
function exporterKeys(): array
{
    return array_values(array_filter(array_map(fn (string $class) => app($class)->key(), ListExporters::CLASSES), fn (string $k) => $k !== 'users'));
}

test('every exporter writes exactly its scoped rows, with markings, as the requester', function (string $list) {
    $exporter = ListExporters::find($list);
    $filters = exportFilters($list, $this->inside);
    $reason = $exporter->sensitive() ? 'Operation planning for the quarterly security review.' : null;

    foreach ([$this->dg, $this->sd] as $user) {
        if (! $user->can('exportList', [ReportRun::class, $exporter])) {
            continue; // e.g. oversight lists: DG and DDGs only
        }
        $expected = app(JurisdictionContext::class)->actingAs($user, fn () => $exporter->count($user, $exporter->filters($filters, $user)));
        $run = exportList($user, $list, $filters, reason: $reason);
        $rows = ExportFiles::rows($run);

        expect($run->status)->toBe(ReportRunStatus::Completed, "{$list}: {$run->error_message}")
            ->and($run->row_count)->toBe($expected)
            ->and(count($rows))->toBe($expected + 4)
            ->and($rows[0][0])->toBe(mb_strtoupper($run->classification->label()))
            ->and($rows[1][0])->toStartWith("Generated for {$user->name}")
            ->and($rows[2][0])->toStartWith($run->reference())
            ->and($rows[3])->toBe(array_map(fn ($c) => $c->label, $exporter->selected(null)));
        CrossFixtures::system();
    }
})->with(fn () => exporterKeys());

test('the state director\'s exports never include another state\'s records', function (string $list) {
    $exporter = ListExporters::find($list);
    $count = fn (User $user) => app(JurisdictionContext::class)->actingAs($user, fn () => $exporter->count($user, []));

    expect($count($this->sd))->toBeLessThan($count($this->dg))
        ->and($count($this->sd))->toBeGreaterThan(0);
})->with(['arrests', 'threats', 'flashpoints', 'coverage_reports', 'formations', 'communities', 'criminal_groups', 'incidents', 'states', 'lgas', 'senatorial_districts', 'persons_of_interest']);

test('exports hold the same rows as their page for the same filters', function () {
    $lga = $this->world->lgas['A1a'];
    Community::factory()->in($lga)->create(['name' => 'Ogbomoso Junction']);
    Community::factory()->in($this->world->lgas['A1b'])->create(['name' => 'Elsewhere Village']);

    // Communities: page "results" names vs the export's names, filtered by LGA.
    $page = CrossFixtures::signIn($this->sd)->get('/communities?lga='.$lga->ulid)->assertOk()->viewData('page')['props'];
    $export = ExportFiles::data(exportList($this->sd, 'communities', ['lga' => $lga->ulid]));
    expect(collect($export)->pluck('Name')->sort()->values()->all())->toBe(collect($page['results']['data'])->pluck('name')->sort()->values()->all())
        ->and(collect($export)->pluck('Name'))->toContain('Ogbomoso Junction')->not->toContain('Elsewhere Village');

    // Arrests: references, as the page lists them (all in the SD's state).
    $page = CrossFixtures::signIn($this->sd)->get('/arrests')->assertOk()->viewData('page')['props'];
    $export = ExportFiles::data(exportList($this->sd, 'arrests'));
    expect(collect($export)->pluck('Reference')->all())->toBe(collect($page['arrests']['data'])->pluck('reference_no')->all());

    // Flashpoints: names in the page's order (by concern).
    $page = CrossFixtures::signIn($this->dg)->get('/flashpoints')->assertOk()->viewData('page')['props'];
    $export = ExportFiles::data(exportList($this->dg, 'flashpoints'));
    expect(collect($export)->pluck('Name')->all())->toBe(collect($page['flashpoints']['data'])->pluck('name')->all());

    // LGAs filtered by state.
    $state = $this->world->states['A1'];
    $page = CrossFixtures::signIn($this->dg)->get('/lgas?state='.$state->ulid)->assertOk()->viewData('page')['props'];
    $export = ExportFiles::data(exportList($this->dg, 'lgas', ['state' => $state->ulid]));
    expect(collect($export)->pluck('LGA')->all())->toBe(collect($page['lgas']['data'])->pluck('name')->all());

    // States filtered by zone (Phase 11F), with the page's figures.
    $zone = $this->world->zones['A'];
    $page = CrossFixtures::signIn($this->dg)->get('/states?zone='.$zone->ulid)->assertOk()->viewData('page')['props'];
    $export = ExportFiles::data(exportList($this->dg, 'states', ['zone' => $zone->ulid]));
    expect(collect($export)->pluck('State')->all())->toBe(collect($page['states'])->pluck('name')->all())
        ->and(collect($export)->pluck('LGAs')->map(fn ($n) => (int) $n)->all())->toBe(collect($page['states'])->pluck('lgas')->all())
        ->and(count($export))->toBe(2);
});

test('exports respect the clearance ceiling and are marked at the highest level they hold', function () {
    $secret = Arrest::factory()->in($this->world->lgas['A1a'])->withPerson(null)->create([
        'classification' => Classification::Secret, 'created_by' => CrossFixtures::chiefOf($this->world->lgas['A1b'])->id,
    ]);

    $dsd = exportList($this->dsd, 'arrests');
    $sd = exportList($this->sd, 'arrests');

    expect(collect(ExportFiles::data($dsd))->pluck('Reference'))->not->toContain($secret->reference_no)
        ->and($dsd->classification)->toBe(Classification::Restricted)
        ->and(collect(ExportFiles::data($sd))->pluck('Reference'))->toContain($secret->reference_no)
        ->and($sd->classification)->toBe(Classification::Secret)
        ->and(ExportFiles::rows($sd)[0][0])->toBe('SECRET');
});

test('rich text is exported as plain text and formulas are neutralised', function () {
    $community = Community::factory()->in($this->world->lgas['A1a'])->create([
        'name' => '=HYPERLINK("http://evil.test","Click")',
        'notes' => '<p><strong>Bold</strong> remark with <em>emphasis</em></p>',
    ]);

    foreach ([ReportFormat::Csv, ReportFormat::Xlsx] as $format) {
        $run = exportList($this->sd, 'communities', ['lga' => $this->world->lgas['A1a']->ulid], $format, ['name', 'notes']);
        $row = collect(ExportFiles::data($run))->first(fn (array $r) => str_contains($r['Name'], 'HYPERLINK'));

        expect($row['Name'])->toStartWith("'=HYPERLINK")
            ->and($row['Notes'])->toBe('Bold remark with emphasis')
            ->and(ExportFiles::bytes($run))->not->toContain('<strong>');
    }
    expect($community->exists)->toBeTrue();
});

test('chosen columns are exported in list order; unknown columns are refused', function () {
    $run = exportList($this->sd, 'arrests', columns: ['status', 'reference']);
    expect(ExportFiles::rows($run)[3])->toBe(['Reference', 'Status']);

    expect(fn () => exportList($this->sd, 'arrests', columns: ['reference', 'nin']))->toThrow(ValidationException::class, 'Choose columns offered');
});

test('a PDF table carries the markings and the chosen columns', function () {
    $run = exportList($this->sd, 'communities', format: ReportFormat::Pdf);
    $text = ExportFiles::pdfText($run);

    expect($run->status)->toBe(ReportRunStatus::Completed, (string) $run->error_message)
        ->and($text)->toContain('RESTRICTED')
        ->and($text)->toContain("Generated for {$this->sd->name}")
        ->and($text)->toContain('Communities')
        ->and($text)->toContain('Ward');
});

test('large exports need step-up; caps are enforced per format', function () {
    config(['buffalo.reports.confirm_rows' => 1, 'buffalo.exports.pdf_max_rows' => 1]);

    expect(fn () => exportList($this->dg, 'arrests', stepUp: false))->toThrow(StepUpRequired::class)
        ->and(exportList($this->dg, 'arrests')->row_count)->toBe(2)
        ->and(fn () => exportList($this->dg, 'arrests', format: ReportFormat::Pdf))->toThrow(ValidationException::class, 'PDF tables are limited to 1');

    // Below the threshold, no code is asked.
    config(['buffalo.reports.confirm_rows' => 10_000]);
    expect(exportList($this->dg, 'arrests', stepUp: false)->status)->toBe(ReportRunStatus::Completed);
});

test('who may export what: deputies export communities and arrests but not persons; super admins only users', function () {
    $persons = ['list' => 'persons', 'format' => 'csv', 'filters' => [], 'reason' => 'Twenty characters or more of reason.'];

    foreach (['communities', 'arrests'] as $list) {
        CrossFixtures::signIn($this->dsd)->postJson("/exports/{$list}/preview", ['filters' => []])->assertOk();
        CrossFixtures::signIn($this->dsd)->post('/exports', ['list' => $list, 'format' => 'xlsx', 'filters' => []])->assertRedirect();
    }
    CrossFixtures::signIn($this->dsd)->postJson('/exports/persons/preview', ['filters' => []])->assertForbidden();
    CrossFixtures::signIn($this->dsd)->post('/exports', $persons)->assertForbidden();
    CrossFixtures::signIn($this->chief)->postJson('/exports/persons_of_interest/preview', ['filters' => []])->assertForbidden();
    CrossFixtures::signIn($this->chief)->postJson('/exports/arrests/preview', ['filters' => []])->assertOk();
    // Oversight lists: DG and DDGs only.
    CrossFixtures::signIn($this->sd)->postJson('/exports/access_requests/preview', ['filters' => []])->assertForbidden();
    CrossFixtures::signIn($this->dg)->postJson('/exports/access_requests/preview', ['filters' => []])->assertOk();

    $admin = $this->world->users['super_admin'];
    CrossFixtures::signIn($admin)->postJson('/exports/arrests/preview', ['filters' => []])->assertForbidden();
    CrossFixtures::signIn($admin)->postJson('/exports/lgas/preview', ['filters' => []])->assertForbidden();
    CrossFixtures::signIn($admin)->postJson('/exports/users/preview', ['filters' => []])->assertOk()->assertJsonPath('step_up', true);
    CrossFixtures::signIn($this->dg)->postJson('/exports/users/preview', ['filters' => []])->assertForbidden();
    CrossFixtures::signIn($this->dg)->postJson('/exports/nonsense/preview', ['filters' => []])->assertNotFound();

    // The users list always needs a fresh code.
    expect(fn () => exportList($admin, 'users', stepUp: false))->toThrow(StepUpRequired::class)
        ->and(exportList($admin, 'users')->status)->toBe(ReportRunStatus::Completed);
});

test('the preview offers the columns, formats and controls that apply', function () {
    CrossFixtures::signIn($this->sd)->postJson('/exports/arrests/preview', ['filters' => []])
        ->assertOk()
        ->assertJsonPath('rows', 1)
        ->assertJsonPath('sensitive', false)
        ->assertJsonPath('step_up', false)
        ->assertJsonPath('reason_min', null)
        ->assertJsonPath('defaults', ['reference', 'arrested_at', 'agency', 'persons', 'offences', 'status', 'lga', 'incident'])
        ->assertJsonPath('formats.*.value', ['xlsx', 'csv', 'pdf']);

    CrossFixtures::signIn($this->sd)->postJson('/exports/persons/preview', ['filters' => []])
        ->assertOk()->assertJsonPath('sensitive', true)->assertJsonPath('step_up', true)->assertJsonPath('reason_min', 20)->assertJsonPath('sensitive_left', 3);

    // The page's own filter rules apply.
    CrossFixtures::signIn($this->sd)->postJson('/exports/arrests/preview', ['filters' => ['status' => 'nonsense']])->assertUnprocessable();
});

test('the export request queues a run and shows it in My reports; only the requester downloads it, logged', function () {
    $response = CrossFixtures::signIn($this->sd)->post('/exports', ['list' => 'communities', 'format' => 'csv', 'filters' => [], 'columns' => ['name']]);
    $run = ReportRun::query()->latest('id')->firstOrFail();
    $response->assertRedirect('/reports?run='.$run->ulid);

    expect($run->report_type)->toBe(ReportType::ListExport)
        ->and($run->parameters['list'])->toBe('communities')
        ->and($run->parameters['columns'])->toBe(['name'])
        ->and($run->is_sensitive)->toBeFalse();

    CrossFixtures::signIn($this->dg)->get("/reports/runs/{$run->ulid}/download")->assertForbidden();
    CrossFixtures::signIn($this->sd)->get("/reports/runs/{$run->ulid}/download")->assertOk();

    $log = RecordAccessLog::query()->where('subject_type', $run->getMorphClass())->where('subject_id', $run->id)->sole();
    expect($log->action)->toBe(AccessAction::Export)
        ->and($log->user_id)->toBe($this->sd->id)
        ->and($log->context)->toBe('list');

    // The run's summary names the list.
    CrossFixtures::signIn($this->sd)->get('/reports')->assertInertia(fn ($page) => $page
        ->where('runs.0.summary', 'Communities · no filters'));
});

test('list exports count toward the daily row and report quotas', function () {
    config(['buffalo.exports.daily_rows' => 1]);

    exportList($this->sd, 'arrests');

    expect(fn () => exportList($this->sd, 'arrests'))->toThrow(ExportQuotaExceeded::class, 'exported rows');
});

test('a run whose list the requester may no longer export fails generically', function () {
    $run = exportList($this->dsd, 'communities');
    expect($run->status)->toBe(ReportRunStatus::Completed);

    DB::table('report_runs')->where('id', $run->id)->update(['status' => 'queued', 'parameters' => json_encode(['list' => 'persons', 'filters' => [], 'columns' => []])]);
    (new GenerateReport($run->id))->handle(app(JurisdictionContext::class));

    expect($run->fresh()?->status)->toBe(ReportRunStatus::Failed);
});

test('no exporter offers an encrypted field or a photo as a column', function () {
    foreach (ListExporters::all() as $exporter) {
        /** @var ListExporter $exporter */
        $keys = array_map(fn ($c) => $c->key, $exporter->columns());
        // Encrypted personal fields; staff emails (the users list) and the
        // addresses of places (formations, sites) are not encrypted fields.
        $forbidden = match (true) {
            $exporter->key() === 'users' => ['nin', 'phone', 'date_of_birth', 'photo', 'photos'],
            $exporter->sensitive() => ['nin', 'phone', 'email', 'address', 'date_of_birth', 'photo', 'photos'],
            default => ['nin', 'phone', 'email', 'date_of_birth', 'photo', 'photos'],
        };
        expect(array_intersect($keys, $forbidden))->toBe([], $exporter->key());
    }
});
