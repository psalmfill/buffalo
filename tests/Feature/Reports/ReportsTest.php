<?php

use App\Actions\Reports\RequestReport;
use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Enums\PoliticalOfficeType;
use App\Enums\ReportFormat;
use App\Enums\ReportRunStatus;
use App\Enums\ReportType;
use App\Enums\ThreatUrgency;
use App\Events\ReportRunUpdated;
use App\Exceptions\StepUpRequired;
use App\Jobs\GenerateReport;
use App\Models\CoverageReport;
use App\Models\Incident;
use App\Models\IncidentPerson;
use App\Models\IncidentType;
use App\Models\PersonOfInterest;
use App\Models\PoliticalOffice;
use App\Models\RecordAccessLog;
use App\Models\ReportRun;
use App\Models\ReportSchedule;
use App\Models\StateProfile;
use App\Models\User;
use App\Notifications\ReportReady;
use App\Support\Jurisdiction\JurisdictionContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Threats\ThreatFixtures as T;

/*
| Phase 7D. World: state A1 (LGAs A1a, A1b), A2, B1. Fixture incidents,
| all submitted "now" (inside a SITREP's 24 hours):
| - A1a: one restricted (the chief's), one secret (another officer's);
| - A1b and B1: one restricted each;
| - A1a: a draft and an invalid report (never included).
*/
beforeEach(function () {
    Notification::fake();
    Storage::fake('reports');
    CrossFixtures::system();
    $this->travelTo(CarbonImmutable::parse('2026-09-20 12:00', 'Africa/Lagos'));
    $this->world = JurisdictionWorld::build();
    $this->dg = $this->world->users['director_general'];
    $this->sd = $this->world->users['state_director'];
    $this->dsd = $this->world->users['deputy_state_director'];
    $this->chief = $this->world->users['lga_chief'];
    $this->admin = $this->world->users['super_admin'];
    $this->type = IncidentType::factory()->create(['name' => 'Kidnapping', 'code' => 'kidnapping', 'category' => 'crime']);

    $make = function (string $lga, string $reference, array $attrs = [], IncidentStatus $status = IncidentStatus::Verified) {
        $creator = $attrs['creator'] ?? $this->chief;
        unset($attrs['creator']);

        return W::at(W::draft($this->world->lgas[$lga], $creator, ['incident_type_id' => $this->type->id, 'occurred_at' => now()->subHours(3), ...$attrs]), $status,
            $status === IncidentStatus::Draft ? [] : ['reference_no' => $reference]);
    };

    $this->own = $make('A1a', 'BUF/A1/A1A/2026/000001', ['title' => 'Travellers abducted at toll gate']);
    $this->secret = $make('A1a', 'BUF/A1/A1A/2026/000002', ['classification' => Classification::Secret, 'creator' => CrossFixtures::chiefOf($this->world->lgas['A1b'])]);
    $this->neighbour = $make('A1b', 'BUF/A1/A1B/2026/000003');
    $this->other = $make('B1', 'BUF/B1/B1/2026/000004');
    $make('A1a', 'draft', [], IncidentStatus::Draft);
    $make('A1a', 'BUF/A1/A1A/2026/000099', [], IncidentStatus::Invalid);
});

/**
 * Requests and (synchronously) generates a report as $user.
 *
 * @param  array<string, mixed>  $parameters
 */
function generateReport(User $user, ReportType $type, array $parameters = [], ReportFormat $format = ReportFormat::Pdf, bool $stepUp = false): ReportRun
{
    $run = app(JurisdictionContext::class)->actingAs($user, fn () => app(RequestReport::class)($user, $type, $format, $parameters, stepUpConfirmed: $stepUp));

    return $run->refresh();
}

/** @return list<string> the text of each PDF page */
function reportPages(ReportRun $run): array
{
    $path = Storage::disk('reports')->path((string) $run->file_path);
    $text = (string) shell_exec('pdftotext -layout '.escapeshellarg($path).' - 2>/dev/null');

    return array_values(array_filter(explode("\f", $text), fn (string $p) => trim($p) !== ''));
}

/** @return list<list<mixed>> spreadsheet rows (blank cells dropped) */
function reportRows(ReportRun $run): array
{
    $path = Storage::disk('reports')->path((string) $run->file_path);

    if ($run->format === ReportFormat::Csv) {
        $rows = array_map(fn (string $line) => str_getcsv($line, escape: ''), array_filter(explode("\n", ltrim((string) file_get_contents($path), "\u{FEFF}"))));

        return array_values(array_map(fn (array $r) => array_values(array_filter($r, fn ($v) => $v !== '' && $v !== null)), $rows));
    }

    $reader = new XlsxReader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = array_values(array_filter($row->toArray(), fn ($v) => $v !== '' && $v !== null));
        }
    }
    $reader->close();

    return $rows;
}

/*
|--------------------------------------------------------------------------
| Permissions
|--------------------------------------------------------------------------
*/

test('report types per role: monthly summaries for DG, DDG, SD and DSD; super admins export lists only', function () {
    // Phase 11E: the incident list moved into the list exporters (type "list"),
    // and single-record PDFs gained new types; the old incident_list type is
    // kept for earlier runs only and is never generated.
    $all = array_values(array_diff(array_map(fn (ReportType $t) => $t->value, ReportType::cases()), ['incident_list']));
    $lga = array_values(array_diff($all, ['monthly']));
    $expected = [
        'director_general' => $all, 'ddg_national' => $all, 'state_director' => $all, 'deputy_state_director' => $all,
        'lga_chief' => $lga, 'super_admin' => ['list'], 'inactive_dg' => [],
    ];

    foreach ($expected as $role => $types) {
        $user = $this->world->users[$role];
        $allowed = array_values(array_map(fn (ReportType $t) => $t->value, array_filter(ReportType::cases(), fn (ReportType $t) => $user->can('generate', [ReportRun::class, $t]))));
        expect($allowed)->toBe($types, $role);
    }

    // The reports page offers only the types it can set up; record PDFs and
    // list exports start on their own pages.
    CrossFixtures::signIn($this->admin)->get('/reports')->assertOk()
        ->assertInertia(fn ($page) => $page->where('types', []));
    CrossFixtures::signIn($this->chief)->post('/reports', ['type' => 'incident_list', 'format' => 'csv'])->assertForbidden();
    CrossFixtures::signIn($this->chief)->post('/reports', ['type' => 'monthly', 'format' => 'pdf'])->assertForbidden();
    CrossFixtures::signIn($this->chief)->get('/reports')->assertOk()
        ->assertInertia(fn ($page) => $page->component('reports/index')->where('types', fn ($types) => collect($types)->pluck('value')->all() === ['sitrep', 'weekly', 'incident', 'lga_profile', 'state_profile', 'coverage', 'analytics']));
});

/*
|--------------------------------------------------------------------------
| Content follows the requester's scopes
|--------------------------------------------------------------------------
*/

test('the SITREP lists exactly the requester\'s scoped incidents; drafts, invalid and above-clearance excluded', function () {
    $refs = fn (ReportRun $run) => collect(['000001', '000002', '000003', '000004', '000099'])
        ->filter(fn (string $n) => str_contains(implode("\n", reportPages($run)), $n))->values()->all();

    $chief = generateReport($this->chief, ReportType::Sitrep);
    $sd = generateReport($this->sd, ReportType::Sitrep);
    $dsd = generateReport($this->dsd, ReportType::Sitrep);
    $dg = generateReport($this->dg, ReportType::Sitrep);

    expect($chief->status)->toBe(ReportRunStatus::Completed)
        ->and($refs($chief))->toBe(['000001'])
        ->and($refs($dsd))->toBe(['000001', '000003'])   // secret is above a DSD's clearance
        ->and($refs($sd))->toBe(['000001', '000002', '000003'])
        ->and($refs($dg))->toBe(['000001', '000002', '000003', '000004']);
});

test('the run classification is the highest level included', function () {
    expect(generateReport($this->chief, ReportType::Sitrep)->classification)->toBe(Classification::Restricted)
        ->and(generateReport($this->dsd, ReportType::Sitrep)->classification)->toBe(Classification::Restricted)
        ->and(generateReport($this->sd, ReportType::Sitrep)->classification)->toBe(Classification::Secret);

    $pages = reportPages(generateReport($this->sd, ReportType::Sitrep));
    expect($pages[0])->toContain('SECRET');
});

test('a threat disseminated to another state\'s LGA appears in that LGA chief\'s next SITREP', function () {
    $a2Chief = CrossFixtures::chiefOf($this->world->lgas['A2']);
    $threat = T::threat($this->world->lgas['A1a'], ['urgency' => ThreatUrgency::Immediate, 'title' => 'Convoy expected on the border road', 'created_by' => $this->sd->id]);

    expect(implode("\n", reportPages(generateReport($a2Chief, ReportType::Sitrep))))->not->toContain('Convoy expected');

    CrossFixtures::signIn($this->sd)->post("/threats/{$threat->ulid}/disseminations", [
        'targets' => [['level' => 'lga', 'ulid' => $this->world->lgas['A2']->ulid]],
        'reason' => 'Armed men expected to cross into your area this week.',
    ])->assertSessionHasNoErrors();

    $text = implode("\n", reportPages(generateReport($a2Chief, ReportType::Sitrep)));
    expect($text)->toContain('Convoy expected')->toContain('Shared with us (1)');
});

test('markings are on every PDF page: classification top and bottom, generated for, reference, page X of Y, watermark', function () {
    // Enough incidents for several pages.
    foreach (range(10, 80) as $n) {
        W::at(W::draft($this->world->lgas['A1a'], $this->chief, ['incident_type_id' => $this->type->id, 'occurred_at' => now()->subHours(2)]), IncidentStatus::Verified, ['reference_no' => 'BUF/A1/A1A/2026/0000'.$n]);
    }

    $run = generateReport($this->chief, ReportType::Sitrep);
    $pages = reportPages($run);
    expect(count($pages))->toBeGreaterThan(1)->and($run->page_count)->toBe(count($pages));

    foreach ($pages as $i => $page) {
        $n = $i + 1;
        expect(substr_count($page, 'RESTRICTED'))->toBeGreaterThanOrEqual(2, "banners on page {$n}")
            ->and($page)->toContain("Generated for {$this->chief->name}")
            ->and($page)->toContain($run->reference())
            ->and($page)->toContain("Page {$n} of ".count($pages));
        // Header plus the diagonal watermark (raw text keeps the rotated run together).
        $raw = (string) shell_exec('pdftotext -raw -f '.$n.' -l '.$n.' '.escapeshellarg(Storage::disk('reports')->path((string) $run->file_path)).' -');
        // Rotated text can lose spaces in extraction ("Mr.Armando"): compare without whitespace.
        $squash = fn (string $t) => (string) preg_replace('/\s+/u', '', $t);
        expect(substr_count($squash($raw), $squash($this->chief->name)))->toBeGreaterThanOrEqual(2, "watermark on page {$n}");
    }
});

test('a report with no data still generates and says so', function () {
    $quiet = CrossFixtures::chiefOf($this->world->lgas['A2']);
    $run = generateReport($quiet, ReportType::Sitrep);

    expect($run->status)->toBe(ReportRunStatus::Completed)
        ->and(implode("\n", reportPages($run)))->toContain('No records in this period');
});

test('weekly and monthly summaries render charts within the requester\'s scopes', function () {
    $weekly = generateReport($this->sd, ReportType::Weekly, ['end' => '2026-09-20']);
    $monthly = generateReport($this->dg, ReportType::Monthly, ['month' => '2026-09']);

    expect($weekly->status)->toBe(ReportRunStatus::Completed)
        ->and($monthly->status)->toBe(ReportRunStatus::Completed)
        ->and($weekly->classification)->toBe(Classification::Secret);
    $text = implode("\n", reportPages($weekly));
    expect($text)->toContain('Weekly summary')->toContain('Incidents over time')->toContain('Notable incidents')
        ->not->toContain($this->world->lgas['B1']->name);
    expect(implode("\n", reportPages($monthly)))->toContain('Monthly summary')->toContain('compared with the same month last year');
});

/*
|--------------------------------------------------------------------------
| Incident list export
|--------------------------------------------------------------------------
*/

test('incident list exports (now a list exporter): scoped rows, markings first, no person names, formulas neutralised', function () {
    DB::table('incidents')->where('id', $this->own->id)->update(['title' => '=HYPERLINK("http://evil.test","x")']);
    $person = CrossFixtures::person($this->world->lgas['A1a'], ['first_name' => 'Zebulon', 'last_name' => 'Quartermain', 'other_names' => null]);
    IncidentPerson::query()->forceCreate(['incident_id' => $this->own->id, 'state_id' => $this->own->state_id, 'lga_id' => $this->own->lga_id, 'person_id' => $person->id, 'involvement' => 'suspect']);

    foreach ([ReportFormat::Xlsx, ReportFormat::Csv] as $format) {
        $run = generateReport($this->dsd, ReportType::ListExport, ['list' => 'incidents', 'filters' => []], $format);
        $rows = reportRows($run);
        $flat = implode(' ', array_map(fn ($r) => implode(' ', array_map('strval', $r)), $rows));

        expect($run->status)->toBe(ReportRunStatus::Completed)
            ->and($run->row_count)->toBe(2)                        // own + neighbour (not secret, draft, invalid or B1)
            ->and($rows[0])->toBe(['RESTRICTED'])
            ->and($rows[1][0])->toStartWith("Generated for {$this->dsd->name}")
            ->and($rows[2][0])->toStartWith($run->reference())
            ->and($flat)->toContain('\'=HYPERLINK')
            ->and($flat)->not->toContain('Zebulon')->not->toContain('Quartermain')
            ->and($flat)->not->toContain('Armed men abducted travellers')   // no descriptions
            ->and($flat)->not->toContain('000002')->not->toContain('000004')->not->toContain('000099');
        $header = collect($rows)->first(fn ($r) => ($r[0] ?? null) === 'Reference');
        expect($header)->toContain('Persons: Suspect')->toContain('Casualties: Killed');
    }
});

test('incident list exports are capped and need step-up above the threshold', function () {
    config(['buffalo.reports.confirm_rows' => 1, 'buffalo.reports.max_rows' => 3]);
    $list = ['list' => 'incidents', 'filters' => []];

    expect(fn () => generateReport($this->sd, ReportType::ListExport, $list, ReportFormat::Csv))
        ->toThrow(StepUpRequired::class);
    expect(generateReport($this->sd, ReportType::ListExport, $list, ReportFormat::Csv, stepUp: true)->row_count)->toBe(3);

    expect(fn () => generateReport($this->dg, ReportType::ListExport, $list, ReportFormat::Csv, stepUp: true))
        ->toThrow(ValidationException::class, 'limited to 3');

    CrossFixtures::signIn($this->dg)->postJson('/exports/incidents/preview', ['filters' => []])
        ->assertOk()->assertJsonPath('rows', 4)->assertJsonPath('step_up', true)
        ->assertJsonPath('formats.0', ['value' => 'xlsx', 'limit' => 3, 'over_limit' => true]);
});

/*
|--------------------------------------------------------------------------
| LGA profile: only current public office holders are named
|--------------------------------------------------------------------------
*/

test('the LGA profile names only current public office holders', function () {
    $lga = $this->world->lgas['A1a'];
    $current = CrossFixtures::person($lga, ['first_name' => 'Adaeze', 'last_name' => 'Chairwoman', 'other_names' => null]);
    $past = CrossFixtures::person($lga, ['first_name' => 'Former', 'last_name' => 'Holder', 'other_names' => null]);
    $commissioner = CrossFixtures::person($lga, ['first_name' => 'Kunle', 'last_name' => 'Commissioner', 'other_names' => null]);
    $poi = CrossFixtures::person($lga, ['first_name' => 'Wanted', 'last_name' => 'Suspect', 'other_names' => null]);
    PoliticalOffice::factory()->forPerson($current)->inLga(PoliticalOfficeType::LgaChairman, $lga)->create();
    PoliticalOffice::factory()->forPerson($past)->inLga(PoliticalOfficeType::LgaChairman, $lga)->ended()->create();
    PoliticalOffice::factory()->forPerson($commissioner)->stateWide(PoliticalOfficeType::Commissioner, $lga->state)->create();
    PersonOfInterest::factory()->create(['person_id' => $poi->id]);

    $run = generateReport($this->chief, ReportType::LgaProfile, ['lga' => $lga->ulid]);
    $text = implode("\n", reportPages($run));

    expect($run->status)->toBe(ReportRunStatus::Completed)
        ->and($text)->toContain('Current public office holders')->toContain('Adaeze Chairwoman')
        ->not->toContain('Former Holder')->not->toContain('Kunle Commissioner')->not->toContain('Wanted Suspect');

    // Out of jurisdiction: refused at request time.
    expect(fn () => generateReport($this->chief, ReportType::LgaProfile, ['lga' => $this->world->lgas['A1b']->ulid]))
        ->toThrow(ValidationException::class);
});

test('the state profile PDF holds the profile and public office holders only, marked at its highest level (Phase 11F)', function () {
    $lga = $this->world->lgas['A1a'];
    $state = $this->world->states['A1'];
    $state->forceFill(['capital' => 'Capital Town'])->save();
    StateProfile::factory()->forState($state)->create(['borders' => '<p>Benin Republic at Seme</p>']);
    $governor = CrossFixtures::person($lga, ['first_name' => 'Babajide', 'last_name' => 'Governor', 'other_names' => null]);
    $party = CrossFixtures::person($lga, ['first_name' => 'Party', 'last_name' => 'Chairman', 'other_names' => null]);
    $past = CrossFixtures::person($lga, ['first_name' => 'Former', 'last_name' => 'Governor', 'other_names' => null]);
    $poi = CrossFixtures::person($lga, ['first_name' => 'Wanted', 'last_name' => 'Suspect', 'other_names' => null]);
    PoliticalOffice::factory()->forPerson($governor)->stateWide(PoliticalOfficeType::Governor, $state)->create();
    PoliticalOffice::factory()->forPerson($party)->stateWide(PoliticalOfficeType::PartyOfficial, $state)->create();
    PoliticalOffice::factory()->forPerson($past)->stateWide(PoliticalOfficeType::Governor, $state)->ended()->create();
    PersonOfInterest::factory()->create(['person_id' => $poi->id]);

    $run = generateReport($this->sd, ReportType::StateProfile, ['state' => $state->ulid]);
    $pages = reportPages($run);
    $text = implode("\n", $pages);

    expect($run->status)->toBe(ReportRunStatus::Completed)
        ->and($run->classification)->toBe(Classification::Secret)
        ->and($pages[0])->toContain('SECRET')->toContain("Generated for {$this->sd->name}")
        ->and($text)->toContain($state->name.' State profile')->toContain('Capital Town')->toContain('Benin Republic at Seme')
        ->toContain($lga->name)->toContain('Senatorial districts')
        ->toContain('Current public office holders')->toContain('Babajide Governor')
        ->toContain('Incidents in the last 90 days')->toContain('Kidnapping')
        ->not->toContain('Party Chairman')->not->toContain('Former Governor')->not->toContain('Wanted Suspect');

    // The deputy cannot see the secret incident: the file is restricted.
    expect(generateReport($this->dsd, ReportType::StateProfile, ['state' => $state->ulid])->classification)->toBe(Classification::Restricted);

    // An LGA chief gets the reference sections only, for their own state only.
    $chief = implode("\n", reportPages(generateReport($this->chief, ReportType::StateProfile, ['state' => $state->ulid])));
    expect($chief)->toContain('Senatorial districts')->not->toContain('Incidents in the last 90 days')->not->toContain('Security presence')->not->toContain('Population estimate');
    expect(fn () => generateReport($this->chief, ReportType::StateProfile, ['state' => $this->world->states['A2']->ulid]))
        ->toThrow(ValidationException::class);
    expect(fn () => generateReport($this->admin, ReportType::StateProfile, ['state' => $state->ulid]))
        ->toThrow(ValidationException::class);
});

test('single-record reports need view on the record', function () {
    expect(fn () => generateReport($this->dsd, ReportType::Incident, ['incident' => $this->secret->ulid]))->toThrow(ValidationException::class)
        ->and(fn () => generateReport($this->chief, ReportType::Incident, ['incident' => $this->neighbour->ulid]))->toThrow(ValidationException::class);

    $run = generateReport($this->sd, ReportType::Incident, ['incident' => $this->secret->ulid]);
    expect($run->classification)->toBe(Classification::Secret)
        ->and(implode("\n", reportPages($run)))->toContain('000002');
});

/*
|--------------------------------------------------------------------------
| Generation, download, expiry
|--------------------------------------------------------------------------
*/

test('a deactivated requester\'s queued run fails with a generic message', function () {
    $user = User::factory()->lgaChief($this->world->lgas['A1a'])->create();
    config(['buffalo.reports.queue_connection' => 'redis-reports']);
    Queue::fake();
    $run = generateReport($user, ReportType::Sitrep);
    $user->forceFill(['is_active' => false])->save();

    (new GenerateReport($run->id))->handle(app(JurisdictionContext::class));

    expect($run->refresh()->status)->toBe(ReportRunStatus::Failed)
        ->and($run->error_message)->toContain('not active')
        ->and($run->file_path)->toBeNull();
});

test('only the requester can download; downloads are logged and integrity-checked', function () {
    $run = generateReport($this->chief, ReportType::Sitrep);
    expect(Storage::disk('reports')->exists("runs/{$run->ulid}.pdf"))->toBeTrue()
        ->and($run->sha256)->toBe(hash('sha256', Storage::disk('reports')->get("runs/{$run->ulid}.pdf")))
        ->and($run->expires_at?->toDateString())->toBe(now()->addDays(30)->toDateString());

    foreach ([$this->dg, $this->sd, $this->dsd, $this->admin] as $other) {
        CrossFixtures::signIn($other)->get("/reports/runs/{$run->ulid}/download")->assertForbidden();
    }

    $response = CrossFixtures::signIn($this->chief)->get("/reports/runs/{$run->ulid}/download");
    $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain($run->fileName());

    $log = RecordAccessLog::query()->where('subject_type', $run->getMorphClass())->where('subject_id', $run->id)->sole();
    expect($log->action)->toBe(AccessAction::Export)->and($log->context)->toBe('sitrep')
        ->and($log->getAttributes()['user_id'])->toBe($this->chief->id);

    // A tampered file is refused (and not logged again).
    Storage::disk('reports')->put("runs/{$run->ulid}.pdf", 'tampered');
    CrossFixtures::signIn($this->chief)->get("/reports/runs/{$run->ulid}/download")->assertStatus(409);
    expect(RecordAccessLog::query()->where('subject_type', $run->getMorphClass())->count())->toBe(1);
});

test('expired files are deleted and their runs marked expired', function () {
    $run = generateReport($this->chief, ReportType::Sitrep);
    $this->travel(31)->days();

    $this->artisan('buffalo:expire-reports')->assertSuccessful();

    expect($run->refresh()->status)->toBe(ReportRunStatus::Expired)
        ->and($run->file_path)->toBeNull()
        ->and(Storage::disk('reports')->exists("runs/{$run->ulid}.pdf"))->toBeFalse();
    CrossFixtures::signIn($this->chief)->get("/reports/runs/{$run->ulid}/download")->assertStatus(410);
});

test('real-time status payloads carry only the run ULID and status', function () {
    Event::fake([ReportRunUpdated::class]);
    $run = generateReport($this->chief, ReportType::Sitrep);

    Event::assertDispatched(ReportRunUpdated::class, function (ReportRunUpdated $e) use ($run) {
        return $e->broadcastWith() === ['run' => $run->ulid, 'status' => $e->status]
            && $e->broadcastOn()->name === 'private-users.'.$this->chief->ulid;
    });
    Event::assertDispatchedTimes(ReportRunUpdated::class, 2); // running, completed
});

test('the reports page lists only the requester\'s own runs', function () {
    $mine = generateReport($this->chief, ReportType::Sitrep);
    $theirs = generateReport($this->sd, ReportType::Sitrep);

    CrossFixtures::signIn($this->chief)->get('/reports')->assertOk()
        ->assertInertia(fn ($page) => $page->has('runs', 1)->where('runs.0.ulid', $mine->ulid)->where('runs.0.downloadable', true)
            ->missing('runs.0.id')->missing('runs.0.file_path'));
    expect(json_encode(CrossFixtures::signIn($this->chief)->get('/reports')->viewData('page')))->not->toContain($theirs->ulid);
});

/*
|--------------------------------------------------------------------------
| Scheduled reports
|--------------------------------------------------------------------------
*/

test('due schedules are generated as the owner, notify without attachments, and SITREPs cover since the last run', function () {
    CrossFixtures::signIn($this->chief)->post('/reports/schedules/defaults/daily_sitrep')->assertSessionHasNoErrors();
    $schedule = ReportSchedule::query()->sole();
    expect($schedule->next_run_at->setTimezone('Africa/Lagos')->format('Y-m-d H:i'))->toBe('2026-09-21 07:00');

    $this->travelTo(CarbonImmutable::parse('2026-09-21 07:02', 'Africa/Lagos'));
    $this->artisan('buffalo:run-report-schedules')->assertSuccessful();

    $run = ReportRun::query()->sole();
    expect($run->requested_by)->toBe($this->chief->id)
        ->and($run->report_schedule_id)->toBe($schedule->id)
        ->and($run->status)->toBe(ReportRunStatus::Completed)
        ->and($run->parameters)->not->toHaveKey('since');  // first run: the last 24 hours
    expect($schedule->refresh()->next_run_at->setTimezone('Africa/Lagos')->format('Y-m-d H:i'))->toBe('2026-09-22 07:00');

    Notification::assertSentTo($this->chief, ReportReady::class, function (ReportReady $n) use ($run) {
        $mail = $n->toMail($this->chief);

        return $n->title($this->chief) === 'Your daily situation report is ready'
            && $n->url($this->chief) === '/reports?run='.$run->ulid
            && $mail->attachments === [] && $mail->rawAttachments === [];
    });

    $this->travelTo(CarbonImmutable::parse('2026-09-22 07:01', 'Africa/Lagos'));
    $this->artisan('buffalo:run-report-schedules')->assertSuccessful();
    expect(ReportRun::query()->latest('id')->first()?->parameters['since'])->toBe($schedule->last_run_at?->toIso8601String());
});

test('schedules of inactive or locked owners are paused, not run', function () {
    CrossFixtures::signIn($this->chief)->post('/reports/schedules/defaults/daily_sitrep');
    CrossFixtures::signIn($this->sd)->post('/reports/schedules/defaults/weekly_summary');
    $this->chief->forceFill(['is_active' => false])->save();
    $this->sd->forceFill(['locked_until' => now()->addDays(30)])->save();

    $this->travel(8)->days();
    $this->artisan('buffalo:run-report-schedules')->assertSuccessful();

    expect(ReportRun::query()->count())->toBe(0)
        ->and(ReportSchedule::query()->where('is_active', true)->count())->toBe(0);
});

test('schedules are the owner\'s own; LGA chiefs get no monthly default', function () {
    CrossFixtures::signIn($this->chief)->post('/reports/schedules/defaults/monthly_summary')->assertForbidden();
    CrossFixtures::signIn($this->sd)->post('/reports/schedules/defaults/monthly_summary')->assertSessionHasNoErrors();
    $schedule = ReportSchedule::query()->sole();

    CrossFixtures::signIn($this->dg)->delete("/reports/schedules/{$schedule->ulid}")->assertForbidden();
    CrossFixtures::signIn($this->dg)->patch("/reports/schedules/{$schedule->ulid}", ['is_active' => false])->assertForbidden();
    CrossFixtures::signIn($this->sd)->patch("/reports/schedules/{$schedule->ulid}", ['is_active' => false])->assertSessionHasNoErrors();
    expect($schedule->refresh()->is_active)->toBeFalse();

    CrossFixtures::signIn($this->chief)->get('/reports/schedules')->assertOk()
        ->assertInertia(fn ($page) => $page->where('defaults', fn ($d) => collect($d)->pluck('key')->all() === ['daily_sitrep', 'weekly_summary']));
});

test('coverage and analytics reports follow the requester\'s scopes', function () {
    CrossFixtures::system();
    $neighbourReport = CoverageReport::factory()->in($this->world->lgas['A1b'])->create(['status' => 'submitted', 'submitted_at' => now(), 'overall_rating' => 'adequate', 'summary' => 'Patrols covered the main roads.']);

    expect(fn () => generateReport($this->chief, ReportType::Coverage, ['report' => $neighbourReport->ulid]))->toThrow(ValidationException::class);
    $coverage = generateReport($this->sd, ReportType::Coverage, ['report' => $neighbourReport->ulid]);
    expect($coverage->status)->toBe(ReportRunStatus::Completed)
        ->and(implode("\n", reportPages($coverage)))->toContain($this->world->lgas['A1b']->name)->toContain('Workflow history');

    $chief = generateReport($this->chief, ReportType::Analytics, ['tab' => 'incidents', 'filters' => ['period' => '7d']]);
    $dg = generateReport($this->dg, ReportType::Analytics, ['tab' => 'incidents', 'filters' => ['period' => '7d']]);
    expect($chief->classification)->toBe(Classification::Restricted)
        ->and($dg->classification)->toBe(Classification::Secret)
        ->and(implode("\n", reportPages($chief)))->toContain('Analytics — Incidents')->not->toContain($this->world->states['B1']->name)
        ->and(implode("\n", reportPages($dg)))->toContain($this->world->states['B1']->name);
});
