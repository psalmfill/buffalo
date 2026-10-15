<?php

use App\Enums\IncidentStatus;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\CoverageReport;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\FlashpointAssessment;
use App\Models\Incident;
use App\Models\IncidentType;
use App\Models\IncidentUpdate;
use App\Models\LgaProfile;
use App\Models\Organization;
use App\Models\OrganizationSector;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\PersonOfInterest;
use App\Models\Religion;
use App\Models\ReligiousInstitution;
use App\Models\SecurityAgency;
use App\Models\ThreatReport;
use App\Models\ThreatType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Flashpoints\FlashpointFixtures as F;
use Tests\Support\Incidents\WorkflowFixtures as W;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Persons\PersonFixtures;
use Tests\Support\Threats\ThreatFixtures as T;

/*
| Phase 11B: every rich text field, saved over HTTP with malicious HTML sent
| directly (bypassing the editor), is sanitised on the server and gets its
| plain text copy.
*/

const RICH_EVIL = '<p><strong>Key fact</strong> <img src=x onerror=alert(1)></p>'
    .'<ul><li><p>Suspect <a href="javascript:alert(1)" onclick="steal()">one</a></p></li><li><p style="color:red">Suspect two</p></li></ul>'
    .'<script>alert(1)</script><iframe src="https://example.com"></iframe><svg onload=alert(1)></svg>';

const RICH_EVIL_TEXT = "Key fact\n\n- Suspect one\n- Suspect two";

beforeEach(function () {
    Notification::fake();
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->lga = $this->world->lgas['A1a'];
    $this->chief = $this->world->users['lga_chief'];
    $this->sd = $this->world->users['state_director'];
    $this->community = Community::factory()->in($this->lga)->create();
    $this->agency = SecurityAgency::factory()->create();
    $this->threatType = ThreatType::factory()->create(['name' => 'Kidnapping']);
});

/**
 * Each scenario saves the evil HTML into its rich fields as a user who may,
 * and returns the saved record and the fields to check.
 *
 * @return array<string, Closure(): array{0: Model, 1: list<string>}>
 */
function richScenarios(): array
{
    $save = function (User $user, string $method, string $url, array $data): void {
        CrossFixtures::signIn($user)->{$method}($url, $data)->assertSessionHasNoErrors();
        CrossFixtures::system();
    };
    $latest = fn (string $class) => $class::query()->latest('id')->firstOrFail();

    return [
        'incident description and action taken' => function () use ($save, $latest) {
            $type = IncidentType::factory()->create();
            $save(test()->chief, 'post', '/incidents', [
                'incident_type' => $type->ulid, 'details' => [], 'title' => 'Travellers abducted',
                'description' => RICH_EVIL, 'severity' => 'high', 'occurred_at' => now()->subHours(3)->format('Y-m-d\TH:i'),
                'occurred_at_precision' => 'approximate', 'lga' => test()->lga->ulid, 'community' => null, 'latitude' => null,
                'longitude' => null, 'location_description' => null, 'action_taken' => RICH_EVIL, 'classification' => 'restricted',
                'casualties' => [], 'persons' => [], 'groups' => [], 'agencies' => [], 'properties' => [],
            ]);

            return [$latest(Incident::class), ['description', 'action_taken']];
        },
        'incident resolution summary' => function () use ($save) {
            $incident = W::at(W::draft(test()->lga, test()->chief), IncidentStatus::UnderInvestigation);
            $save(test()->sd, 'post', "/incidents/{$incident->ulid}/resolve", ['summary' => RICH_EVIL]);

            return [Incident::query()->findOrFail($incident->id), ['resolution_summary']];
        },
        'incident follow-up' => function () use ($save, $latest) {
            $incident = W::at(W::draft(test()->lga, test()->chief), IncidentStatus::Verified);
            $save(test()->chief, 'post', "/incidents/{$incident->ulid}/updates", ['type' => 'follow_up', 'body' => RICH_EVIL, 'classification' => 'restricted']);

            return [$latest(IncidentUpdate::class), ['body']];
        },
        'LGA profile' => function () use ($save, $latest) {
            $fields = ['history', 'economic_activities', 'major_roads', 'waterways', 'borders', 'general_notes'];
            $save(test()->chief, 'put', '/lgas/'.test()->lga->ulid.'/profile', [...array_fill_keys($fields, RICH_EVIL), 'terrain' => []]);

            return [$latest(LgaProfile::class), $fields];
        },
        'community notes' => function () use ($save, $latest) {
            $save(test()->chief, 'post', '/lgas/'.test()->lga->ulid.'/communities', ['name' => 'Oke-Ado', 'type' => 'town', 'other_names' => [], 'notes' => RICH_EVIL]);

            return [$latest(Community::class), ['notes']];
        },
        'religious institution notes' => function () use ($save, $latest) {
            $save(test()->chief, 'post', '/lgas/'.test()->lga->ulid.'/religious-institutions', [
                'community' => test()->community->ulid, 'religion' => Religion::factory()->create()->ulid, 'name' => 'Central Mosque', 'type' => 'mosque', 'notes' => RICH_EVIL,
            ]);

            return [$latest(ReligiousInstitution::class), ['notes']];
        },
        'community security note' => function () use ($save, $latest) {
            $save(test()->chief, 'post', '/lgas/'.test()->lga->ulid.'/security-notes', [
                'community' => test()->community->ulid, 'category' => 'land_dispute', 'title' => 'Boundary tension', 'description' => RICH_EVIL,
                'classification' => 'restricted', 'observed_at' => now()->subDays(2)->toDateString(), 'status' => 'active',
            ]);

            return [$latest(CommunitySecurityNote::class), ['description']];
        },
        'person description' => function () use ($save, $latest) {
            $save(test()->chief, 'post', '/persons', PersonFixtures::payload(test()->lga, ['description' => RICH_EVIL, 'confirm_new' => true]));

            return [$latest(Person::class), ['description']];
        },
        'person of interest' => function () use ($save, $latest) {
            $person = Person::factory()->in(test()->lga)->create();
            $save(test()->chief, 'post', "/persons/{$person->ulid}/person-of-interest", [
                'offence_categories' => [], 'known_offences' => RICH_EVIL, 'modus_operandi' => RICH_EVIL, 'latitude' => null, 'longitude' => null,
                'last_known_location_description' => null, 'last_sighted_at' => null, 'risk_level' => 'high', 'status' => 'at_large', 'comment' => 'Named by two witnesses.',
            ]);

            return [$latest(PersonOfInterest::class), ['known_offences', 'modus_operandi']];
        },
        'criminal group' => function () use ($save, $latest) {
            $save(test()->chief, 'post', '/criminal-groups', [
                'lga' => test()->lga->ulid, 'name' => 'Black Axe Waterside', 'other_names' => [], 'type' => 'cult', 'description' => RICH_EVIL,
                'estimated_size' => 40, 'status' => 'active', 'classification' => 'restricted',
            ]);

            return [$latest(CriminalGroup::class), ['description']];
        },
        'agency formation notes' => function () use ($save, $latest) {
            $save(test()->chief, 'post', '/formations', [
                'lga' => test()->lga->ulid, 'agency' => test()->agency->ulid, 'name' => 'Ikeja Division', 'formation_type' => 'division',
                'is_temporary' => false, 'status' => 'active', 'classification' => 'restricted', 'notes' => RICH_EVIL,
            ]);

            return [$latest(AgencyFormation::class), ['notes']];
        },
        'coverage report' => function () use ($save, $latest) {
            test()->travelTo(now()->setDate(2026, 10, 10)->setTime(10, 0));
            $save(test()->chief, 'post', '/coverage-reports', [
                'lga' => test()->lga->ulid, 'period_type' => 'monthly', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30',
                'overall_rating' => 'partial', 'summary' => RICH_EVIL, 'gaps_identified' => RICH_EVIL, 'recommendations' => RICH_EVIL, 'classification' => 'restricted',
                'deployments' => [['agency' => test()->agency->ulid, 'personnel_count' => 40, 'vehicles_count' => 4, 'patrol_frequency' => 'daily', 'communities' => [test()->community->ulid], 'areas_covered' => 'Town centre']],
            ]);

            return [$latest(CoverageReport::class), ['summary', 'gaps_identified', 'recommendations']];
        },
        'organization description' => function () use ($save, $latest) {
            $sector = OrganizationSector::factory()->create(['code' => 'rich_text_sector']);
            $save(test()->chief, 'post', '/organizations', ['name' => 'Delta Petroleum', 'other_names' => [], 'type' => 'company', 'sector' => $sector->ulid, 'status' => 'active', 'description' => RICH_EVIL]);

            return [$latest(Organization::class), ['description']];
        },
        'organization site security notes' => function () use ($save, $latest) {
            $organization = Organization::factory()->create();
            $save(test()->chief, 'post', "/organizations/{$organization->ulid}/sites", [
                'lga' => test()->lga->ulid, 'name' => 'Export Terminal', 'site_type' => 'terminal', 'security_relevance' => 'critical_infrastructure',
                'has_private_security' => false, 'status' => 'active', 'classification' => 'restricted', 'security_notes' => RICH_EVIL,
            ]);

            return [$latest(OrganizationSite::class), ['security_notes']];
        },
        'flashpoint and its first assessment' => function () use ($save, $latest) {
            $save(test()->chief, 'post', '/flashpoints', F::payload(test()->lga, test()->threatType, ['description' => RICH_EVIL, 'assessment' => RICH_EVIL, 'recommended_action' => RICH_EVIL]));

            return [$latest(Flashpoint::class), ['description', 'recommended_action']];
        },
        'flashpoint assessment' => function () use ($save, $latest) {
            $flashpoint = F::flashpoint(test()->lga);
            $save(test()->sd, 'post', "/flashpoints/{$flashpoint->ulid}/assessments", ['concern_level' => 'high', 'status' => 'active', 'assessment' => RICH_EVIL, 'recommended_action' => RICH_EVIL]);

            return [$latest(FlashpointAssessment::class), ['assessment', 'recommended_action']];
        },
        'threat report' => function () use ($save, $latest) {
            $save(test()->chief, 'post', '/threats', T::payload(test()->lga, test()->threatType, ['description' => RICH_EVIL, 'recommended_action' => RICH_EVIL, 'target_description' => RICH_EVIL]));

            return [$latest(ThreatReport::class), ['description', 'recommended_action', 'target_description']];
        },
    ];
}

test('malicious HTML sent directly is sanitised and gets a plain text copy', function (string $scenario) {
    [$record, $fields] = richScenarios()[$scenario]();

    $row = DB::table($record->getTable())->where('id', $record->getKey())->first();

    foreach ($fields as $field) {
        $html = (string) $row->{$field};

        expect(strtolower($html))
            ->toContain('<strong>key fact</strong>')
            ->toContain('<li>')
            ->not->toContain('<img')
            ->not->toContain('onerror')
            ->not->toContain('onclick')
            ->not->toContain('javascript:')
            ->not->toContain('<script')
            ->not->toContain('<iframe')
            ->not->toContain('<svg')
            ->not->toContain('style=')
            ->and($row->{"{$field}_text"})->toBe(RICH_EVIL_TEXT);
    }
})->with(array_keys(richScenarios()));

test('append-only rows written now are marked as HTML', function () {
    [$update] = richScenarios()['incident follow-up']();
    [$assessment] = richScenarios()['flashpoint assessment']();

    expect(DB::table('incident_updates')->where('id', $update->getKey())->value('body_format'))->toBe('html')
        ->and(DB::table('flashpoint_assessments')->where('id', $assessment->getKey())->value('body_format'))->toBe('html');
});

test('the resolution summary is rich text, its status history comment plain text', function () {
    [$incident] = richScenarios()['incident resolution summary']();

    expect(DB::table('incident_status_histories')->where('incident_id', $incident->getKey())->where('to_status', 'resolved')->value('comment'))->toBe(RICH_EVIL_TEXT);
});

test('limits and required checks count plain text over HTTP', function () {
    $incident = W::at(W::draft($this->lga, $this->chief), IncidentStatus::Verified);
    $url = "/incidents/{$incident->ulid}/updates";

    CrossFixtures::signIn($this->chief)->post($url, ['type' => 'follow_up', 'body' => '<p><strong></strong><br></p>', 'classification' => 'restricted'])
        ->assertSessionHasErrors(['body' => 'The body field is required.']);
    CrossFixtures::signIn($this->chief)->post($url, ['type' => 'follow_up', 'body' => '<p>'.str_repeat('a', 20001).'</p>', 'classification' => 'restricted'])
        ->assertSessionHasErrors('body');
    // 20,000 characters of text in heavy markup is still within the limit.
    CrossFixtures::signIn($this->chief)->post($url, ['type' => 'follow_up', 'body' => '<p><strong><em>'.str_repeat('a', 20000).'</em></strong></p>', 'classification' => 'restricted'])
        ->assertSessionHasNoErrors();
});
