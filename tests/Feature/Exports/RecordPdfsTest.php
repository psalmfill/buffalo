<?php

use App\Actions\Organizations\SitePhotos\AddSitePhoto;
use App\Actions\Reports\RequestReport;
use App\Enums\AccessAction;
use App\Enums\Classification;
use App\Enums\IncidentStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportRunStatus;
use App\Enums\ReportType;
use App\Exceptions\ExportQuotaExceeded;
use App\Exceptions\StepUpRequired;
use App\Models\Arrest;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupMember;
use App\Models\FlashpointAssessment;
use App\Models\FlashpointIncident;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\RecordAccessLog;
use App\Models\ReportRun;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Reports\LocationSketch;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Exports\ExportFiles;
use Tests\Support\Flashpoints\FlashpointFixtures as F;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\PersonFixtures;
use Tests\Support\Threats\ThreatFixtures;

/*
| Phase 11E.1 and 11E.3: single records as PDFs through the report
| pipeline: flashpoints (with a server-drawn location sketch), organization
| sites, arrests, threat reports, criminal groups and persons.
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
});

function printRecord(User $user, ReportType $type, string $ulid, bool $stepUp = false): ReportRun
{
    return app(JurisdictionContext::class)->actingAs($user, fn () => app(RequestReport::class)(
        $user, $type, ReportFormat::Pdf, [(string) $type->recordParameter() => $ulid], stepUpConfirmed: $stepUp,
    ))->refresh();
}

function flashpointWithLinks(object $test): array
{
    $lga = $test->world->lgas['A1a'];
    $flashpoint = F::flashpoint($lga, ['name' => 'Ajegunle jetty']);
    $visible = F::incident($lga, $test->chief, 0.001, IncidentStatus::Verified, ['title' => 'Jetty robbery']);
    $secret = F::incident($lga, CrossFixtures::chiefOf($test->world->lgas['A1b']), 0.002, IncidentStatus::Verified, [
        'title' => 'Hidden case', 'classification' => Classification::Secret,
    ]);
    CrossFixtures::system();
    DB::table('incidents')->where('id', $visible->id)->update(['reference_no' => 'BUF/A1/A1A/2026/000101']);
    DB::table('incidents')->where('id', $secret->id)->update(['reference_no' => 'BUF/A1/A1A/2026/000102']);
    foreach ([$visible, $secret] as $incident) {
        (new FlashpointIncident)->forceFill(['flashpoint_id' => $flashpoint->id, 'state_id' => $lga->state_id, 'lga_id' => $lga->id, 'incident_id' => $incident->id, 'linked_by' => $test->sd->id, 'linked_at' => now()])->save();
    }
    foreach (['Calm since the raid.', 'Tension rising after the market dispute.'] as $i => $text) {
        (new FlashpointAssessment)->forceFill([
            'flashpoint_id' => $flashpoint->id, 'state_id' => $lga->state_id, 'lga_id' => $lga->id,
            'concern_level' => $i === 0 ? 'moderate' : 'high', 'status' => 'active', 'assessment' => $text,
            'assessed_by' => $test->sd->id, 'assessed_at' => now()->subDays(2 - $i),
        ])->save();
    }

    return [$flashpoint, $visible, $secret];
}

test('the location sketch is an inline SVG drawn from coordinates only', function () {
    $outline = ['type' => 'MultiPolygon', 'coordinates' => [[[[3.30, 6.40], [3.40, 6.40], [3.40, 6.50], [3.30, 6.50], [3.30, 6.40]]]]];
    $svg = (new LocationSketch($outline, 6.45, 3.35, 2000, [[6.46, 3.36], [6.44, 3.34]]))->svg();

    expect($svg)->toStartWith('<svg xmlns="http://www.w3.org/2000/svg"')
        ->and(substr_count($svg, '<path '))->toBeGreaterThanOrEqual(2)    // boundary and north arrow
        ->and(substr_count($svg, '<circle '))->toBe(4)                   // radius, two incidents, the point
        ->and($svg)->toContain('class="scale"')->toContain('>N</text>')
        // No tiles, no links, no external references of any kind.
        ->and($svg)->not->toContain('<image')->not->toContain('href')->not->toContain('url(')
        ->and(preg_match_all('#https?://#', $svg))->toBe(1);             // the SVG namespace only

    // Without a boundary, the point and radius are still drawn.
    expect((new LocationSketch(null, 6.45, 3.35, 500))->svg())->toContain('<circle ')->not->toContain('fill="#f1f5f9"');
});

test('the flashpoint PDF is rendered without any request but Gotenberg, and holds the sketch and only visible links', function () {
    [$flashpoint] = flashpointWithLinks($this);
    $sent = [];
    Http::preventStrayRequests();
    Http::fake([rtrim((string) config('buffalo.reports.gotenberg_url'), '/').'/*' => function (HttpRequest $request) use (&$sent) {
        $sent[] = $request;

        return Http::response("%PDF-1.4\n/Type /Page\n%%EOF", 200);
    }]);

    $run = printRecord($this->dsd, ReportType::Flashpoint, $flashpoint->ulid);

    expect($run->status)->toBe(ReportRunStatus::Completed, (string) $run->error_message)
        ->and($sent)->toHaveCount(1);
    $html = collect($sent[0]->data())->firstWhere('filename', 'index.html')['contents'] ?? '';
    expect($html)->toContain('<svg xmlns="http://www.w3.org/2000/svg" class="sketch"')
        ->and($html)->toContain('Ajegunle jetty')
        ->and($html)->toContain('BUF/A1/A1A/2026/000101')
        ->and($html)->not->toContain('BUF/A1/A1A/2026/000102')     // secret: above the deputy's clearance
        ->and($html)->toContain('Tension rising after the market dispute.')
        ->and($html)->toContain('Calm since the raid.')
        ->and($html)->not->toContain('src="http')->not->toContain('href="http');
});

test('the flashpoint PDF: markings, assessment history and links for each requester', function () {
    [$flashpoint] = flashpointWithLinks($this);

    $run = printRecord($this->sd, ReportType::Flashpoint, $flashpoint->ulid);
    $text = ExportFiles::pdfText($run);

    expect($run->status)->toBe(ReportRunStatus::Completed, (string) $run->error_message)
        ->and($run->classification)->toBe(Classification::Secret)          // the secret incident is listed for the SD
        ->and($text)->toContain('SECRET')
        ->and($text)->toContain("Generated for {$this->sd->name}")
        ->and($text)->toContain('Assessment history (2)')
        ->and($text)->toContain('BUF/A1/A1A/2026/000102')
        ->and($text)->toContain('Disseminations');                         // the owning state sees its sharing

    $log = RecordAccessLog::query()->where('subject_type', $flashpoint->getMorphClass())->where('subject_id', $flashpoint->id)->where('action', AccessAction::Print->value)->first();
    expect($log?->context)->toBe('report');

    // Another state cannot print it.
    expect(fn () => printRecord(CrossFixtures::directorOfB1($this->world), ReportType::Flashpoint, $flashpoint->ulid))
        ->toThrow(ValidationException::class, 'Choose a record you can see');
});

test('person PDFs need step-up and show what the requester\'s page shows', function () {
    $person = Person::factory()->in($this->world->lgas['A1a'])->named('Musa', 'Garba', null)->create(['nin' => '61234567890', 'phone' => '08031112222']);

    expect(fn () => printRecord($this->sd, ReportType::Person, $person->ulid))->toThrow(StepUpRequired::class);

    $run = printRecord($this->sd, ReportType::Person, $person->ulid, stepUp: true);
    $page = CrossFixtures::signIn($this->sd)->get("/persons/{$person->ulid}")->viewData('page')['props']['person'];
    $text = ExportFiles::pdfText($run);

    expect($run->status)->toBe(ReportRunStatus::Completed, (string) $run->error_message)
        ->and($page['nin'])->toBe('61234567890')
        ->and($text)->toContain('Musa Garba')
        ->and($text)->toContain('61234567890')
        ->and($text)->toContain('+2348031112222');
});

test('the organization site PDF: details, government security, contacts by name only, and photos', function () {
    $lga = $this->world->lgas['A1a'];
    $site = OrganizationSite::factory()->in($lga)->of(Organization::factory()->create(['name' => 'Gulf Petroleum']))->create(['name' => 'Kano Inland Terminal', 'security_notes' => '<p>Gate 2 is unmanned at night.</p>']);
    (new OrganizationContact)->forceFill(['organization_site_id' => $site->id, 'state_id' => $lga->state_id, 'lga_id' => $lga->id, 'name' => 'Ibrahim Danladi', 'position' => 'Security Manager', 'phone' => '08051234567', 'email' => 'i.danladi@example.test'])->save();
    foreach (range(1, 8) as $i) {
        app(JurisdictionContext::class)->actingAs($this->sd, fn () => app(AddSitePhoto::class)($site, PersonFixtures::png(60, 40), "Photo {$i}", null, $this->sd));
    }
    CrossFixtures::system();

    $run = printRecord($this->sd, ReportType::OrganizationSite, $site->ulid);
    $text = ExportFiles::pdfText($run);
    $pdf = ExportFiles::bytes($run);

    expect($run->status)->toBe(ReportRunStatus::Completed, (string) $run->error_message)
        ->and($text)->toContain('Kano Inland Terminal')
        ->and($text)->toContain('Gate 2 is unmanned at night.')
        ->and($text)->toContain('Ibrahim Danladi')->toContain('Security Manager')
        ->and($text)->not->toContain('8051234567')->not->toContain('i.danladi')
        ->and($text)->toContain('Photo 1')->toContain('Photo 7')->not->toContain('Photo 8')   // the cover and six more
        ->and(preg_match_all('#/Subtype\s*/Image#', $pdf))->toBeGreaterThanOrEqual(7);
});

test('arrest, threat report and criminal group PDFs follow the requester\'s view', function () {
    $lga = $this->world->lgas['A1a'];
    $arrest = Arrest::factory()->in($lga)->withPerson(null, [], 'Tall man in a red cap')->create(['created_by' => $this->sd->id]);
    $threat = ThreatFixtures::threat($lga, ['title' => 'Planned attack on the depot']);
    $group = CriminalGroup::factory()->in($lga)->create(['name' => 'Coastal Boys']);
    $hidden = Person::factory()->in($this->world->lgas['B1'])->named('Outside', 'Member', null)->create();
    $member = Person::factory()->in($lga)->named('Inside', 'Member', null)->create();
    foreach ([$hidden, $member] as $person) {
        (new CriminalGroupMember(['membership_status' => 'confirmed']))->forceFill(['criminal_group_id' => $group->id, 'person_id' => $person->id])->save();
    }

    $texts = [
        ExportFiles::pdfText(printRecord($this->sd, ReportType::Arrest, $arrest->ulid)),
        ExportFiles::pdfText(printRecord($this->sd, ReportType::ThreatReport, $threat->ulid)),
        ExportFiles::pdfText(printRecord($this->sd, ReportType::CriminalGroup, $group->ulid)),
    ];

    expect($texts[0])->toContain($arrest->reference_no)->toContain('Tall man in a red cap')
        ->and($texts[1])->toContain($threat->reference_no)->toContain('Planned attack on the depot')
        ->and($texts[2])->toContain('Coastal Boys')->toContain('Inside Member')->not->toContain('Outside Member');
});

test('record PDFs count toward the report quota and download for the requester only', function () {
    config(['buffalo.exports.daily_report_runs' => 1]);
    $threat = ThreatFixtures::threat($this->world->lgas['A1a']);

    $run = printRecord($this->sd, ReportType::ThreatReport, $threat->ulid);
    expect(fn () => printRecord($this->sd, ReportType::ThreatReport, $threat->ulid))->toThrow(ExportQuotaExceeded::class);

    CrossFixtures::signIn($this->dg)->get("/reports/runs/{$run->ulid}/download")->assertForbidden();
    CrossFixtures::signIn($this->sd)->get("/reports/runs/{$run->ulid}/download")->assertOk();
    expect(RecordAccessLog::query()->where('subject_type', $run->getMorphClass())->where('subject_id', $run->id)->value('context'))->toBe('threat_report');
});

test('the print buttons post to the report pipeline and return to My reports', function () {
    $threat = ThreatFixtures::threat($this->world->lgas['A1a']);

    CrossFixtures::signIn($this->chief)->post('/reports', ['type' => 'threat_report', 'format' => 'pdf', 'parameters' => ['threat' => $threat->ulid]])
        ->assertRedirect();
    expect(ReportRun::query()->latest('id')->first()?->report_type)->toBe(ReportType::ThreatReport);

    // Person records ask for the code first.
    $person = Person::factory()->in($this->world->lgas['A1a'])->create();
    CrossFixtures::signInWithoutStepUp($this->chief)->post('/reports', ['type' => 'person', 'format' => 'pdf', 'parameters' => ['person' => $person->ulid]], ['X-Inertia' => 'true'])
        ->assertStatus(428);
});
