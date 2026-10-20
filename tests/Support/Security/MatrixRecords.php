<?php

namespace Tests\Support\Security;

use App\Actions\Evidence\StoreEvidence;
use App\Actions\Organizations\SitePhotos\AddSitePhoto;
use App\Actions\Persons\AddPersonPhoto;
use App\Enums\Classification;
use App\Enums\PoliticalOfficeType;
use App\Models\AgencyFormation;
use App\Models\Arrest;
use App\Models\BlackspotSuggestion;
use App\Models\Community;
use App\Models\CommunitySecurityNote;
use App\Models\CoverageReport;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupArea;
use App\Models\CriminalGroupMember;
use App\Models\Flashpoint;
use App\Models\FlashpointIncident;
use App\Models\FlashpointSecurityNote;
use App\Models\Incident;
use App\Models\Lga;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\OrganizationSiteFormation;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PersonAssociation;
use App\Models\PersonOfInterest;
use App\Models\PoliticalOffice;
use App\Models\ReligiousInstitution;
use App\Models\User;
use App\Models\VulnerablePopulationStat;
use App\Support\Evidence\EvidenceFileInspector;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Database\Eloquent\Model;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Flashpoints\FlashpointFixtures;
use Tests\Support\Incidents\EvidenceFixtures;
use Tests\Support\Persons\PersonFixtures;
use Tests\Support\Threats\ThreatFixtures;

/**
 * One record of every jurisdiction-scoped model routes bind, all in one LGA
 * and all `restricted` (Phase 8B.6 matrix). Children belong to the parents
 * in the same set, so nested routes resolve consistently.
 */
final class MatrixRecords
{
    /** @var array<class-string<Model>, Model> */
    public array $records = [];

    public static function build(Lga $lga, User $author): self
    {
        CrossFixtures::system();
        $set = new self;
        $add = function (Model $model) use ($set): Model {
            $set->records[$model::class] = $model;

            return $model;
        };
        $restricted = ['classification' => Classification::Restricted];

        $add($lga);
        $add($lga->state);
        $add($author);

        $community = $add(Community::factory()->in($lga)->create());
        $add(ReligiousInstitution::factory()->in($community)->create());
        $add(VulnerablePopulationStat::factory()->forLga($lga)->create());
        $note = $add(CommunitySecurityNote::factory()->in($community)->create($restricted));

        /** @var Person $person */
        $person = $add(CrossFixtures::person($lga, ['nin' => null]));
        $other = CrossFixtures::person($lga, ['nin' => null]);
        $add((new PersonAlias)->forceFill(['person_id' => $person->id, 'alias' => 'Matrix alias', 'created_by' => $author->id]))->save();
        $add((new PersonAssociation)->forceFill(['person_id' => $person->id, 'associated_person_id' => $other->id, 'relationship' => 'family', 'created_by' => $author->id]))->save();
        $add(app(JurisdictionContext::class)->actingAs($author, fn () => app(AddPersonPhoto::class)($person, PersonFixtures::png(), $author)));
        $add(PoliticalOffice::factory()->forPerson($person)->inLga(PoliticalOfficeType::LgaChairman, $lga)->create());
        $add(PersonOfInterest::factory()->forPerson($person)->create());

        $group = $add(CriminalGroup::factory()->in($lga)->create($restricted));
        $add((new CriminalGroupMember(['membership_status' => 'confirmed']))->forceFill(['criminal_group_id' => $group->id, 'person_id' => $person->id]))->save();
        $add((new CriminalGroupArea)->forceFill(['criminal_group_id' => $group->id, 'lga_id' => $lga->id, 'state_id' => $lga->state_id]))->save();

        $formation = $add(AgencyFormation::factory()->in($lga)->create($restricted));
        $add(CoverageReport::factory()->in($lga)->create());

        $site = $add(OrganizationSite::factory()->in($lga)->of(Organization::factory()->create())->create($restricted));
        $add((new OrganizationContact)->forceFill(['organization_site_id' => $site->id, 'state_id' => $lga->state_id, 'lga_id' => $lga->id, 'name' => 'Matrix contact', 'position' => 'Manager']))->save();
        $add((new OrganizationSiteFormation)->forceFill(['organization_site_id' => $site->id, 'agency_formation_id' => $formation->id, 'arrangement' => 'on_call']))->save();
        $add(app(JurisdictionContext::class)->actingAs($author, fn () => app(AddSitePhoto::class)($site, PersonFixtures::png(), 'Matrix photo', null, $author)));
        CrossFixtures::system();

        /** @var Incident $incident */
        $incident = $add(Incident::factory()->in($lga)->by($author)->create([
            'status' => 'verified', 'reference_no' => 'BUF/MX/'.$lga->id.'/2026/'.str_pad((string) $lga->id, 6, '0', STR_PAD_LEFT), 'submitted_at' => now(),
        ]));
        $file = EvidenceFixtures::photo();
        $detected = app(EvidenceFileInspector::class)->inspect((string) $file->getRealPath(), $file->getClientOriginalName());
        $add(app(JurisdictionContext::class)->actingAs($author, fn () => app(StoreEvidence::class)(
            $incident, $file, (array) $detected, ['title' => 'Matrix item', 'description' => null, 'classification' => Classification::Restricted], $author,
        )));
        CrossFixtures::system();

        /** @var Flashpoint $flashpoint */
        $flashpoint = $add(FlashpointFixtures::flashpoint($lga));
        $add((new FlashpointIncident)->forceFill(['flashpoint_id' => $flashpoint->id, 'state_id' => $lga->state_id, 'lga_id' => $lga->id, 'incident_id' => $incident->id, 'linked_by' => $author->id, 'linked_at' => now()]))->save();
        $add((new FlashpointSecurityNote)->forceFill(['flashpoint_id' => $flashpoint->id, 'state_id' => $lga->state_id, 'lga_id' => $lga->id, 'community_security_note_id' => $note->id, 'linked_by' => $author->id, 'linked_at' => now()]))->save();

        $add(ThreatFixtures::threat($lga));
        $add(Arrest::factory()->in($lga)->withPerson(null)->create(['created_by' => $author->id]));

        $add((new BlackspotSuggestion)->forceFill([
            'state_id' => $lga->state_id, 'lga_id' => $lga->id, 'centroid' => Incident::pointFrom(9.05, 7.49), 'radius_meters' => 500,
            'incident_count' => 6, 'first_occurred_at' => now()->subMonth(), 'last_occurred_at' => now(), 'status' => 'open', 'generated_at' => now(),
        ]))->save();

        return $set;
    }

    /** @param class-string $class */
    public function get(string $class): ?Model
    {
        return $this->records[$class] ?? null;
    }
}
