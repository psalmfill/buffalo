<?php

namespace Tests\Support\Persons;

use App\Actions\Persons\MergePersons;
use App\Actions\Persons\UndoPersonMerge;
use App\Enums\IncidentStatus;
use App\Enums\MembershipStatus;
use App\Enums\PoiStatus;
use App\Enums\RiskLevel;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Incident;
use App\Models\IncidentPerson;
use App\Models\OrganizationSite;
use App\Models\Person;
use App\Models\PersonMerge;
use App\Models\PersonOfInterest;
use App\Models\PersonRoleType;
use App\Models\PoliticalOffice;
use App\Models\ReligiousInstitution;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionContext;
use App\Support\Persons\Merge\MergeChoices;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Jurisdiction\JurisdictionWorld;

/**
 * Phase 10A test helpers: run merges and undos as a user.
 */
final class MergeFixtures
{
    public static function merge(User $actor, Person $survivor, Person $source, ?MergeChoices $choices = null): PersonMerge
    {
        return app(JurisdictionContext::class)->actingAs($actor->fresh() ?? $actor, fn () => app(MergePersons::class)(
            self::reload($survivor),
            self::reload($source),
            $choices ?? new MergeChoices,
            $actor,
        ));
    }

    /**
     * @param  list<string>  $keep
     */
    public static function undo(User $actor, PersonMerge $merge, array $keep = [], string $reason = 'Merged in error: different people.'): void
    {
        app(JurisdictionContext::class)->actingAs($actor->fresh() ?? $actor, fn () => app(UndoPersonMerge::class)($merge->fresh() ?? $merge, $actor, $reason, $keep));
    }

    /** The person as stored, through the system context. */
    public static function reload(Person $person): Person
    {
        return app(JurisdictionContext::class)->runAsSystem('test', fn () => Person::withTrashed()->findOrFail($person->id));
    }

    /**
     * A survivor (A1a) and a source (A1b) linked from every module (Phase
     * 10A.9 manifest completeness). Run in the system context.
     *
     * @return array{survivor: Person, source: Person, ids: array<string, list<int>>}
     */
    public static function rich(JurisdictionWorld $world): array
    {
        app(JurisdictionContext::class)->system();
        $ids = [];
        $add = function (string $table, int $id) use (&$ids): int {
            $ids[$table][] = $id;

            return $id;
        };

        $survivor = Person::factory()->in($world->lgas['A1a'])->named('Garba', 'Musa')->create(['phone' => '08030000001', 'occupation' => 'Trader']);
        $source = Person::factory()->in($world->lgas['A1b'])->named('Garba', 'Mussa')->create(['date_of_birth' => '1985-03-04', 'occupation' => 'Farmer', 'address' => '12 Market Road']);
        $other = Person::factory()->in($world->lgas['A1a'])->named('Other', 'Associate')->create();
        $third = Person::factory()->in($world->lgas['A1b'])->named('Third', 'Associate')->create();

        // Aliases: one duplicate of the survivor's, one new.
        foreach ([[$survivor, 'Big Garba'], [$source, 'Big Garba'], [$source, 'Mallam G']] as [$person, $alias]) {
            $add('person_aliases', DB::table('person_aliases')->insertGetId(['ulid' => strtolower((string) Str::ulid()), 'person_id' => $person->id, 'alias' => $alias, 'created_at' => now(), 'updated_at' => now()]));
        }

        // Roles: political leader on both (inactive on the survivor), community leader on the source.
        foreach ([[$survivor, PersonRoleType::POLITICAL_LEADER, false], [$source, PersonRoleType::POLITICAL_LEADER, true], [$source, PersonRoleType::COMMUNITY_LEADER, true]] as [$person, $role, $active]) {
            $add('person_roles', DB::table('person_roles')->insertGetId(['person_id' => $person->id, 'person_role_type_id' => PersonRoleType::idFor($role), 'is_active' => $active, 'notes' => null, 'created_at' => now(), 'updated_at' => now()]));
        }

        // Associations: survivor<->other and source<->other (duplicate pair),
        // source<->survivor (dropped), third<->source (repointed).
        foreach ([[$survivor, $other, 'Business partner'], [$source, $other, 'Seen together'], [$source, $survivor, null], [$third, $source, 'Cousin']] as [$a, $b, $notes]) {
            $add('person_associations', DB::table('person_associations')->insertGetId(['ulid' => strtolower((string) Str::ulid()), 'person_id' => $a->id, 'associated_person_id' => $b->id, 'relationship' => 'associate', 'notes' => $notes, 'created_at' => now(), 'updated_at' => now()]));
        }

        // Persons of interest on both.
        $add('persons_of_interest', PersonOfInterest::factory()->forPerson($survivor)->create(['risk_level' => RiskLevel::Medium, 'status' => PoiStatus::AtLarge, 'offence_categories' => ['kidnapping'], 'known_offences' => 'Kidnapping on the highway.', 'review_due_at' => '2027-06-01'])->id);
        $add('persons_of_interest', PersonOfInterest::factory()->forPerson($source)->create(['risk_level' => RiskLevel::High, 'status' => PoiStatus::UnderSurveillance, 'offence_categories' => ['armed_robbery'], 'known_offences' => 'Armed robbery in 2024.', 'modus_operandi' => 'Night raids.', 'last_sighted_at' => now()->subDays(3), 'review_due_at' => '2027-01-15'])->id);

        $add('political_offices', PoliticalOffice::factory()->create(['person_id' => $source->id])->id);

        // Groups: both members of one (survivor suspected, source confirmed); source alone in another.
        $shared = CriminalGroup::factory()->in($world->lgas['A1a'])->create();
        $own = CriminalGroup::factory()->in($world->lgas['A1b'])->create();
        foreach ([[$shared, $survivor, MembershipStatus::Suspected, '2022-01-01'], [$shared, $source, MembershipStatus::Confirmed, '2020-05-05'], [$own, $source, MembershipStatus::Former, null]] as [$group, $person, $status, $since]) {
            $add('criminal_group_members', DB::table('criminal_group_members')->insertGetId(['ulid' => strtolower((string) Str::ulid()), 'criminal_group_id' => $group->id, 'person_id' => $person->id, 'membership_status' => $status->value, 'since' => $since, 'notes' => 'Notes on '.$person->first_name.' '.$person->last_name, 'created_at' => now(), 'updated_at' => now()]));
        }

        // Incidents: an open one with both as suspects, a closed one with the source.
        $open = Incident::factory()->in($world->lgas['A1a'])->create(['status' => IncidentStatus::Submitted]);
        $closed = Incident::factory()->in($world->lgas['A1b'])->create(['status' => IncidentStatus::Submitted]);
        foreach ([[$open, $survivor, 'suspect', 'Seen at the scene'], [$open, $source, 'suspect', 'Fled north'], [$closed, $source, 'suspect', null], [$open, $source, 'witness', null]] as [$incident, $person, $involvement, $notes]) {
            $add('incident_persons', IncidentPerson::query()->forceCreate(['incident_id' => $incident->id, 'state_id' => $incident->state_id, 'lga_id' => $incident->lga_id, 'person_id' => $person->id, 'involvement' => $involvement, 'notes' => $notes])->id);
        }
        DB::table('incidents')->where('id', $closed->id)->update(['status' => IncidentStatus::Closed->value]);

        // Photos (rows only: a merge never touches files).
        foreach ([[$survivor, 1], [$source, 1], [$source, 2]] as [$person, $order]) {
            $add('media', DB::table('media')->insertGetId(['ulid' => strtolower((string) Str::ulid()), 'uuid' => (string) Str::uuid(), 'model_type' => Person::class, 'model_id' => $person->id, 'collection_name' => Person::PHOTOS, 'name' => 'photo', 'file_name' => 'photo.jpg', 'mime_type' => 'image/jpeg', 'disk' => 'evidence', 'size' => 10, 'manipulations' => '[]', 'custom_properties' => '[]', 'generated_conversions' => '[]', 'responsive_images' => '[]', 'order_column' => $order, 'created_at' => now(), 'updated_at' => now()]));
        }

        // Community, religious and organization links to the source.
        $community = Community::factory()->create(['lga_id' => $world->lgas['A1b']->id, 'state_id' => $world->lgas['A1b']->state_id]);
        DB::table('communities')->where('id', $community->id)->update(['traditional_ruler_id' => $source->id]);
        $add('communities', $community->id);
        $institution = ReligiousInstitution::factory()->create(['community_id' => $community->id]);
        DB::table('religious_institutions')->where('id', $institution->id)->update(['leader_person_id' => $source->id]);
        $add('religious_institutions', $institution->id);
        $site = OrganizationSite::factory()->create(['lga_id' => $world->lgas['A1b']->id]);
        $add('organization_contacts', DB::table('organization_contacts')->insertGetId(['ulid' => strtolower((string) Str::ulid()), 'organization_site_id' => $site->id, 'state_id' => $site->state_id, 'lga_id' => $site->lga_id, 'name' => 'Contact', 'position' => 'Manager', 'person_id' => $source->id, 'created_at' => now(), 'updated_at' => now()]));

        return ['survivor' => $survivor, 'source' => $source, 'ids' => $ids];
    }

    /**
     * Every listed row as stored (without updated_at), for comparisons.
     *
     * @param  array<string, list<int>>  $ids
     * @return array<string, array<int, array<string, mixed>>>
     */
    public static function rows(array $ids): array
    {
        $rows = [];

        foreach ($ids as $table => $list) {
            foreach (DB::table($table)->whereIn('id', $list)->orderBy('id')->get() as $row) {
                $values = (array) $row;
                unset($values['updated_at']);
                $rows[$table][(int) $row->id] = $values;
            }
        }

        return $rows;
    }
}
