<?php

use App\Actions\Reports\RequestReport;
use App\Enums\NotificationSeverity;
use App\Enums\ReportFormat;
use App\Enums\ReportRunStatus;
use App\Enums\ReportType;
use App\Exceptions\ExportQuotaExceeded;
use App\Exceptions\StepUpRequired;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupMember;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\ReportRun;
use App\Models\User;
use App\Notifications\SensitiveExportAlert;
use App\Support\Exports\AgeBand;
use App\Support\Jurisdiction\JurisdictionContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Exports\ExportFiles;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/*
| Phase 11E.2: the sensitive lists (persons, persons of interest, criminal
| group members, organization contacts): SD, DG and DDG only; step-up and a
| reason every time; at most 1,000 rows; at most 3 a day; no encrypted
| fields or photos, ever; an immediate alert to the DG and covering DDGs.
*/

const PLANTED_NIN = '71234567890';
const PLANTED_PHONE = '08031239876';
const REASON = 'Planning the joint patrol of 12 November in Ward 4.';

beforeEach(function () {
    Notification::fake();
    Storage::fake('reports');
    CrossFixtures::system();
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00', 'Africa/Lagos'));
    $this->world = JurisdictionWorld::build();
    $this->dg = $this->world->users['director_general'];
    $this->sd = $this->world->users['state_director'];
    $lga = $this->world->lgas['A1a'];

    $this->person = Person::factory()->in($lga)->named('Tunde', 'Bakare', 'Olaniyi')->create([
        'nin' => PLANTED_NIN,
        'phone' => PLANTED_PHONE,
        'address' => 'Plot 7 Secretariat Close',
        'date_of_birth' => '1990-05-17',
        'occupation' => 'Trader',
    ]);
    $this->poi = PersonOfInterest::factory()->forPerson($this->person)->create();
    $this->group = CriminalGroup::factory()->in($lga)->create(['name' => 'Night Riders']);
    (new CriminalGroupMember(['membership_status' => 'confirmed', 'role_in_group' => 'Lookout']))->forceFill(['criminal_group_id' => $this->group->id, 'person_id' => $this->person->id])->save();
    $this->site = OrganizationSite::factory()->in($lga)->of(Organization::factory()->create(['name' => 'Delta Haulage']))->create(['name' => 'Main depot']);
    (new OrganizationContact)->forceFill([
        'organization_site_id' => $this->site->id, 'state_id' => $lga->state_id, 'lga_id' => $lga->id,
        'name' => 'Ada Okafor', 'position' => 'Security manager', 'phone' => '08055554444', 'email' => 'ada.secret@delta.example', 'is_security_contact' => true,
    ])->save();
});

function sensitiveExport(User $user, string $list, array $filters = [], ?string $reason = REASON, bool $stepUp = true, ReportFormat $format = ReportFormat::Csv): ReportRun
{
    return app(JurisdictionContext::class)->actingAs($user, fn () => app(RequestReport::class)(
        $user, ReportType::ListExport, $format, ['list' => $list, 'filters' => $filters, 'columns' => [], 'reason' => $reason], stepUpConfirmed: $stepUp,
    ))->refresh();
}

test('only SD, DG and DDG may export the sensitive lists', function (string $list) {
    $filters = match ($list) {
        'criminal_group_members' => ['group' => test()->group->ulid],
        'organization_contacts' => ['site' => test()->site->ulid],
        default => [],
    };
    $allowed = ['director_general', 'ddg_zone_a', 'state_director'];

    foreach (['director_general', 'ddg_zone_a', 'state_director', 'deputy_state_director', 'lga_chief', 'super_admin'] as $role) {
        $status = CrossFixtures::signIn($this->world->users[$role])->postJson("/exports/{$list}/preview", ['filters' => $filters])->status();
        expect($status)->toBe(in_array($role, $allowed, true) ? 200 : 403, "{$role} {$list}");
    }
})->with(['persons', 'persons_of_interest', 'criminal_group_members', 'organization_contacts']);

test('every sensitive export needs step-up and a reason of at least 20 characters, stored on the run', function () {
    expect(fn () => sensitiveExport($this->sd, 'persons', stepUp: false))->toThrow(StepUpRequired::class)
        ->and(fn () => sensitiveExport($this->sd, 'persons', reason: 'Too short'))->toThrow(ValidationException::class, 'at least 20 characters')
        ->and(fn () => sensitiveExport($this->sd, 'persons', reason: null))->toThrow(ValidationException::class);

    $run = sensitiveExport($this->sd, 'persons');
    expect($run->status)->toBe(ReportRunStatus::Completed, (string) $run->error_message)
        ->and($run->is_sensitive)->toBeTrue()
        ->and($run->reason)->toBe(REASON);

    // Through the page: the code is asked even for one row, and again for
    // the next export (each confirmation is used up).
    $client = CrossFixtures::signIn($this->sd);
    $payload = ['list' => 'persons', 'format' => 'csv', 'filters' => [], 'reason' => REASON];
    $client->post('/exports', $payload)->assertRedirect();
    $client->post('/exports', $payload, ['X-Inertia' => 'true'])->assertStatus(428);

    CrossFixtures::signInWithoutStepUp($this->sd)->post('/exports', ['list' => 'persons', 'format' => 'csv', 'filters' => [], 'reason' => REASON], ['X-Inertia' => 'true'])
        ->assertStatus(428);
});

test('sensitive exports are capped at the configured rows and limited per day', function () {
    Person::factory()->in($this->world->lgas['A1a'])->create();
    config(['buffalo.exports.sensitive_max_rows' => 1]);

    expect(fn () => sensitiveExport($this->sd, 'persons', ['lga' => $this->world->lgas['A1a']->ulid]))
        ->toThrow(ValidationException::class, 'limited to 1');

    config(['buffalo.exports.sensitive_max_rows' => 1000, 'buffalo.exports.daily_sensitive_exports' => 2]);
    sensitiveExport($this->sd, 'persons');
    sensitiveExport($this->sd, 'persons_of_interest');

    expect(fn () => sensitiveExport($this->sd, 'persons'))->toThrow(ExportQuotaExceeded::class, 'limit of 2 sensitive list exports')
        // Other lists are unaffected.
        ->and(sensitiveExport($this->sd, 'communities', reason: null)->status)->toBe(ReportRunStatus::Completed)
        // Another user has their own limit.
        ->and(sensitiveExport($this->dg, 'persons')->status)->toBe(ReportRunStatus::Completed);
});

test('person exports never contain encrypted fields or photos, in any column or byte', function (ReportFormat $format) {
    $run = sensitiveExport($this->sd, 'persons', format: $format);
    $bytes = $format === ReportFormat::Xlsx ? implode("\n", array_map(fn ($r) => implode(',', $r), ExportFiles::rows($run))) : ExportFiles::bytes($run);

    expect($run->status)->toBe(ReportRunStatus::Completed)
        ->and($bytes)->toContain('Bakare')
        ->and($bytes)->toContain('30–39')
        ->and($bytes)->not->toContain(PLANTED_NIN)
        ->and($bytes)->not->toContain('8031239876')
        ->and($bytes)->not->toContain('Secretariat')
        ->and($bytes)->not->toContain('1990')
        ->and($bytes)->not->toContain('05-17')
        ->and($bytes)->not->toContain('nacl:');
    expect(ExportFiles::rows($run)[3])->toBe(['Name', 'Other names', 'Aliases', 'Gender', 'Age band', 'Occupation', 'Roles', 'LGA', 'State', 'Classification', 'Last updated']);
})->with([ReportFormat::Csv, ReportFormat::Xlsx]);

test('the other sensitive lists keep to their columns: no phone, email or birth date', function () {
    $poi = sensitiveExport($this->sd, 'persons_of_interest');
    $members = sensitiveExport($this->sd, 'criminal_group_members', ['group' => $this->group->ulid]);
    $contacts = sensitiveExport($this->sd, 'organization_contacts', ['site' => $this->site->ulid]);

    expect(ExportFiles::rows($poi)[3])->toBe(['Name', 'Other names', 'Aliases', 'Gender', 'Age band', 'Occupation', 'Roles', 'LGA', 'State', 'Classification', 'Last updated', 'Risk level', 'Status', 'Offence categories', 'Last sighted', 'Review due'])
        ->and(ExportFiles::rows($members)[3])->toBe(['Group', 'Person', 'Role in group', 'Membership status', 'Since'])
        ->and(ExportFiles::rows($contacts)[3])->toBe(['Organization', 'Site', 'Name', 'Position', 'Management', 'Security contact', 'Primary contact']);

    foreach ([$poi, $members, $contacts] as $run) {
        expect(ExportFiles::bytes($run))->not->toContain(PLANTED_NIN)->not->toContain('8031239876')->not->toContain('1990')
            ->not->toContain('8055554444')->not->toContain('ada.secret');
    }
    expect(ExportFiles::bytes($members))->toContain('Night Riders')->toContain('Lookout')
        ->and(ExportFiles::bytes($contacts))->toContain('Ada Okafor')->toContain('Security manager');
});

test('age bands come from the date of birth without revealing it', function () {
    $today = CarbonImmutable::parse('2026-10-21');

    expect(AgeBand::for('1990-05-17', null, $today))->toBe('30–39')
        ->and(AgeBand::for('2010-01-01', null, $today))->toBe('Under 18')
        ->and(AgeBand::for('2007-10-20', null, $today))->toBe('18–19')
        ->and(AgeBand::for(null, 45, $today))->toBe('40–49')
        ->and(AgeBand::for('1930-01-01', null, $today))->toBe('80 and over')
        ->and(AgeBand::for(null, null, $today))->toBe('Unknown');
});

test('a sensitive export alerts the DG and covering DDGs at once, without record content', function () {
    $run = sensitiveExport($this->sd, 'persons_of_interest');

    $check = fn (SensitiveExportAlert $n, string $channel, User $to) => $n->severity() === NotificationSeverity::Critical
        && str_contains($n->body($to), $this->sd->name)
        && str_contains($n->body($to), 'Persons of interest')
        && str_contains($n->body($to), '1 row')
        && str_contains($n->body($to), REASON)
        && str_contains($n->body($to), $run->reference())
        && ! str_contains($n->body($to).$n->title($to), 'Bakare');

    Notification::assertSentTo($this->dg, SensitiveExportAlert::class, fn ($n, $c) => $check($n, 'database', $this->dg));
    Notification::assertSentTo([$this->world->users['ddg_zone_a'], $this->world->users['ddg_national']], SensitiveExportAlert::class);
    Notification::assertNotSentTo([$this->world->users['ddg_zone_b'], $this->sd, $this->world->users['super_admin']], SensitiveExportAlert::class);

    // A DG exporting is not told about their own export; the DDGs are.
    Notification::fake();
    sensitiveExport($this->dg, 'persons');
    Notification::assertNotSentTo($this->dg, SensitiveExportAlert::class);
    Notification::assertSentTo($this->world->users['ddg_national'], SensitiveExportAlert::class);
});

test('a number search is never exported, so no NIN reaches the stored run', function () {
    expect(fn () => sensitiveExport($this->sd, 'persons', ['q' => PLANTED_NIN]))->toThrow(ValidationException::class, 'search by number cannot be exported');

    expect(ReportRun::query()->where('parameters', 'like', '%'.PLANTED_NIN.'%')->exists())->toBeFalse();
});

test('sensitive exports are highlighted in the exports review, with the reason', function () {
    $run = sensitiveExport($this->sd, 'persons');
    CrossFixtures::signIn($this->sd)->get("/reports/runs/{$run->ulid}/download")->assertOk();
    $plain = sensitiveExport($this->sd, 'communities', reason: null);
    CrossFixtures::signIn($this->sd)->get("/reports/runs/{$plain->ulid}/download")->assertOk();

    CrossFixtures::signIn($this->dg)->get('/audit/exports')->assertOk()->assertInertia(fn ($page) => $page
        ->where('entries.data', fn ($rows) => collect($rows)->contains(fn ($r) => $r['sensitive'] === true && $r['reason'] === REASON && str_contains($r['what'], 'List export: Persons'))
            && collect($rows)->contains(fn ($r) => $r['sensitive'] === false && $r['reason'] === null && str_contains($r['what'], 'List export: Communities'))));

    CrossFixtures::signIn($this->dg)->get('/audit/exports?sensitive=1')->assertInertia(fn ($page) => $page
        ->where('entries.data', fn ($rows) => collect($rows)->every(fn ($r) => $r['sensitive'] === true) && count($rows) === 1));
});
