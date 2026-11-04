<?php

use App\Actions\Reports\RequestReport;
use App\Actions\Security\Attachments\StoreReportAttachment;
use App\Enums\Classification;
use App\Enums\CoverageReportStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Models\CoverageReport;
use App\Models\CoverageReportAttachment;
use App\Models\Person;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Analytics\Analytics;
use App\Services\Analytics\AnalyticsFilters;
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
use Tests\Support\Incidents\EvidenceFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 11G.3: the client's access rules for security reports and their
| attachments, without exception. One submitted report with a photo and a
| video in each of LGA A1a (state A1, zone A), A1b (state A1), A2 (state
| A2, zone A) and B1 (zone B):
|
| | User                         | Sees                      |
| |------------------------------|---------------------------|
| | LGA chief of A1a             | A1a only                  |
| | State Director, Deputy (A1)  | A1a and A1b               |
| | DDG of zone A                | A1a, A1b, A2              |
| | DDG (national), DG           | all four                  |
| | Super admin                  | none                      |
|
| Every route: list, detail, edit, attachment thumb, preview, stream and
| download, list export, PDF, dashboards, analytics and global search.
| Out-of-reach records answer 404 (403 without the permission).
*/

const SECURITY_REPORT_REACH = [
    'lga_chief' => ['A1a'],
    'state_director' => ['A1a', 'A1b'],
    'deputy_state_director' => ['A1a', 'A1b'],
    'ddg_zone_a' => ['A1a', 'A1b', 'A2'],
    'ddg_national' => ['A1a', 'A1b', 'A2', 'B1'],
    'director_general' => ['A1a', 'A1b', 'A2', 'B1'],
    'super_admin' => [],
];

beforeEach(function () {
    Notification::fake();
    Storage::fake('evidence');
    Storage::fake('reports');
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->reports = [];
    $this->photos = [];
    $this->videos = [];

    foreach (['A1a', 'A1b', 'A2', 'B1'] as $key) {
        $lga = $this->world->lgas[$key];
        $report = CoverageReport::factory()->in($lga)->create([
            'title' => "Uyo-style report {$key}", 'summary' => "<p>Report of {$key}.</p>",
            'period_type' => 'ad_hoc', 'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
            'status' => CoverageReportStatus::Submitted, 'submitted_at' => now(), 'classification' => Classification::Restricted,
        ]);
        $uploader = User::factory()->directorGeneral()->create();
        $store = fn (UploadedFile $file) => app(JurisdictionContext::class)->actingAs($uploader, fn () => app(StoreReportAttachment::class)(
            $report, $file, (array) app(EvidenceFileInspector::class)->inspect((string) $file->getRealPath(), $file->getClientOriginalName()), "Attachment {$key}", Classification::Restricted, $uploader,
        ));
        $this->reports[$key] = $report;
        $this->photos[$key] = $store(EvidenceFixtures::photo());
        $this->videos[$key] = $store(EvidenceFixtures::mp4());
    }
    CrossFixtures::system();
});

test('each role sees exactly the security reports of its jurisdiction, on every route', function (string $role) {
    $user = $this->world->users[$role];
    $reach = SECURITY_REPORT_REACH[$role];
    $client = fn () => CrossFixtures::signIn($user);

    // The list.
    if ($role === 'super_admin') {
        $client()->get('/security-reports')->assertForbidden();
    } else {
        $listed = collect($client()->get('/security-reports?lens=off')->assertOk()->viewData('page')['props']['reports']['data'])->pluck('ulid')->sort()->values()->all();
        expect($listed)->toBe(collect($reach)->map(fn (string $k) => $this->reports[$k]->ulid)->sort()->values()->all());
    }

    foreach (['A1a', 'A1b', 'A2', 'B1'] as $key) {
        $report = $this->reports[$key];
        $photo = $this->photos[$key];
        $video = $this->videos[$key];
        $inside = in_array($key, $reach, true);
        // Without the permission: 403; outside reach: 404, never a page.
        $refused = $role === 'super_admin' ? [403, 404] : [404];
        $expect = fn ($response, int $ok = 200) => $inside
            ? $response->assertStatus($ok)
            : expect(in_array($response->status(), $refused, true))->toBeTrue("{$role} reached {$key} ({$response->status()})");

        $expect($client()->get("/security-reports/{$report->ulid}"));
        $expect($client()->get("/security-report-attachments/{$photo->ulid}/thumb"));
        $expect($client()->get("/security-report-attachments/{$photo->ulid}/preview"));
        $expect($client()->get("/security-report-attachments/{$photo->ulid}/download"));
        $expect($client()->get("/security-report-attachments/{$video->ulid}/stream"));

        if (! $inside) {
            // Writes are refused as well, before any validation.
            expect($refused)->toContain($client()->get("/security-reports/{$report->ulid}/edit")->status())
                ->and([403, 404])->toContain($client()->postJson("/security-reports/{$report->ulid}/attachments", ['file' => EvidenceFixtures::photo()])->status())
                ->and([403, 404])->toContain($client()->put("/security-report-attachments/{$photo->ulid}", ['caption' => 'x', 'classification' => 'restricted'])->status())
                ->and([403, 404])->toContain($client()->delete("/security-report-attachments/{$photo->ulid}", ['reason' => 'Not mine at all'])->status())
                ->and([403, 404])->toContain($client()->post("/security-reports/{$report->ulid}/review")->status());
        }
    }
})->with(array_keys(SECURITY_REPORT_REACH));

test('scoped queries agree: models, export, analytics and dashboards', function (string $role) {
    $user = $this->world->users[$role];
    $reach = SECURITY_REPORT_REACH[$role];
    $ulids = fn (array $keys, array $set) => collect($keys)->map(fn (string $k) => $set[$k]->ulid)->sort()->values()->all();

    app(JurisdictionContext::class)->actingAs($user, function () use ($user, $reach, $ulids): void {
        expect(CoverageReport::query()->pluck('ulid')->sort()->values()->all())->toBe($ulids($reach, $this->reports))
            ->and(CoverageReportAttachment::query()->pluck('ulid')->sort()->values()->all())
            ->toBe(collect($ulids($reach, $this->photos))->merge($ulids($reach, $this->videos))->sort()->values()->all());

        if ($role = $user->getRole()?->value) {
            $exporter = ListExporters::find('coverage_reports');

            if ($user->can('viewAny', CoverageReport::class)) {
                expect($exporter->query($user, [])->pluck('coverage_reports.ulid')->sort()->values()->all())->toBe($ulids($reach, $this->reports));

                // Analytics: compliance by place lists only places in reach.
                $analytics = app(Analytics::class);
                $area = $analytics->area($user, 'coverage');
                expect($area)->not->toBeNull();
                $chart = collect($area === null ? [] : $analytics->charts($user, $area, AnalyticsFilters::from([], $user)))->firstWhere('key', 'compliance_by_place');
                $labels = collect($chart['table'] ?? [])->pluck('label')->all();
                $outside = collect(['A1a', 'A1b', 'A2', 'B1'])->reject(fn (string $k) => in_array($k, $reach, true));
                foreach ($outside as $key) {
                    expect($labels)->not->toContain($this->world->lgas[$key]->name)
                        ->and($labels)->not->toContain($this->world->lgas[$key]->state->name);
                }
            }
        }
    });
})->with(array_keys(SECURITY_REPORT_REACH));

test('the state dashboard lists only the state\'s own LGAs; national compliance covers only the zones in reach', function () {
    $lgas = fn (User $user) => collect(CrossFixtures::signIn($user)->get('/dashboard')->viewData('page')['props']['coverage']['lgas'] ?? [])->pluck('lga.ulid')->sort()->values()->all();

    expect($lgas($this->world->users['state_director']))->toBe(collect([$this->world->lgas['A1a']->ulid, $this->world->lgas['A1b']->ulid])->sort()->values()->all());

    $compliance = collect(CrossFixtures::signIn($this->world->users['ddg_zone_b'])->get('/dashboard')->viewData('page')['props']['coverage']['compliance'] ?? [])->pluck('state.name')->all();
    expect($compliance)->toBe([$this->world->states['B1']->name]);
});

test('the PDF is built only for a report within reach', function () {
    $request = fn (User $user, string $key) => app(JurisdictionContext::class)->actingAs($user, fn () => app(RequestReport::class)(
        $user, ReportType::Coverage, ReportFormat::Pdf, ['report' => $this->reports[$key]->ulid], stepUpConfirmed: false,
    ));

    expect(fn () => $request($this->world->users['lga_chief'], 'A1b'))->toThrow(ValidationException::class)
        ->and(fn () => $request($this->world->users['state_director'], 'A2'))->toThrow(ValidationException::class)
        ->and(fn () => $request($this->world->users['ddg_zone_a'], 'B1'))->toThrow(ValidationException::class)
        // The super admin may not generate it at all (ReportPolicy::generate).
        ->and($this->world->users['super_admin']->can('generate', [ReportRun::class, ReportType::Coverage]))->toBeFalse();

    expect($request($this->world->users['lga_chief'], 'A1a')->ulid)->toHaveLength(26);
});

test('global search never returns security reports (no provider), so titles cannot leak', function () {
    $hits = CrossFixtures::signIn($this->world->users['lga_chief'])->getJson('/search/quick?q='.urlencode('Uyo-style report B1'))->assertOk()->json();

    $text = (string) json_encode($hits);
    foreach ($this->reports as $report) {
        expect($text)->not->toContain($report->ulid);
    }
    expect($text)->not->toContain('/security-reports/');
});

test('security reports and attachments are neither grantable nor disseminable', function () {
    // Person grants (Phase 3) apply to persons only; disseminations (7A) to
    // Disseminable records (threat reports, flashpoints) only. Neither
    // resolver ever adds a security report or attachment.
    $chief = $this->world->users['lga_chief'];
    $resolver = app(AccessGrantResolver::class);

    foreach ([$this->reports['B1'], $this->photos['B1']] as $record) {
        expect($resolver->hasGrant($chief, $record))->toBeFalse()
            ->and($record)->not->toBeInstanceOf(Disseminable::class)
            ->and($record)->not->toBeInstanceOf(Person::class);
    }
});
