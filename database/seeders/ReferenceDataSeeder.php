<?php

namespace Database\Seeders;

use App\Enums\AgencyType;
use App\Enums\IncidentCategory;
use App\Enums\Severity;
use App\Models\Denomination;
use App\Models\IncidentType;
use App\Models\OffenceType;
use App\Models\OrganizationSector;
use App\Models\PersonRoleType;
use App\Models\PropertyType;
use App\Models\Religion;
use App\Models\SecurityAgency;
use App\Models\ThreatType;
use App\Support\DynamicFields\FieldDefinition;
use App\Support\DynamicFields\SchemaValidator;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Default reference data (Phase 2A): starting values the client can edit.
 *
 * Idempotent and non-destructive: an entry is created only when its code is
 * missing. Existing entries are never touched, so an administrator's edits
 * (names, order, deactivation, detail fields) survive re-seeding.
 */
class ReferenceDataSeeder extends Seeder
{
    private int $created = 0;

    public function run(): void
    {
        DB::transaction(function (): void {
            $this->incidentTypes();
            $this->threatTypes();
            $this->securityAgencies();
            $this->religions();
            $this->propertyTypes();
            $this->organizationSectors();
            $this->personRoleTypes();
            $this->offenceTypes();
        });

        $this->command->info("Reference data: {$this->created} missing entries created.");
    }

    private function incidentTypes(): void
    {
        $weapons = self::options(['firearms' => 'Firearms', 'machetes_knives' => 'Machetes/knives', 'explosives' => 'Explosives', 'other' => 'Other', 'unknown' => 'Unknown']);

        $types = [
            ['road_mishap', 'Road mishap', IncidentCategory::Accident, Severity::Medium, [
                self::field('vehicles_involved', 'Vehicles involved', 'integer', ['min' => 1]),
                self::field('vehicle_types', 'Vehicle types', 'multiselect', ['options' => self::options([
                    'car' => 'Car', 'bus' => 'Bus', 'truck_trailer' => 'Truck/trailer', 'tanker' => 'Tanker',
                    'motorcycle' => 'Motorcycle', 'tricycle' => 'Tricycle', 'other' => 'Other',
                ])]),
                self::field('suspected_cause', 'Suspected cause', 'select', ['options' => self::options([
                    'overspeeding' => 'Overspeeding', 'brake_failure' => 'Brake failure', 'bad_road' => 'Bad road',
                    'dangerous_overtaking' => 'Dangerous overtaking', 'impaired_driving' => 'Impaired driving',
                    'unknown' => 'Unknown', 'other' => 'Other',
                ])]),
                self::field('road_name', 'Road name', 'text'),
            ]],
            ['fire_outbreak', 'Fire outbreak', IncidentCategory::DisasterEnvironmental, Severity::Medium, [
                self::field('fire_type', 'Fire type', 'select', ['options' => self::options([
                    'residential' => 'Residential', 'market' => 'Market', 'industrial' => 'Industrial', 'bush' => 'Bush',
                    'vehicle' => 'Vehicle', 'oil_gas_facility' => 'Oil/gas facility', 'other' => 'Other',
                ])]),
                self::field('suspected_cause', 'Suspected cause', 'text'),
                self::field('fire_service_responded', 'Fire service responded', 'boolean'),
            ]],
            ['cult_related_incident', 'Cult-related incident', IncidentCategory::ViolenceConflict, Severity::High, [
                self::field('groups_involved', 'Groups involved', 'text'),
                self::field('weapons_used', 'Weapons used', 'multiselect', ['options' => $weapons]),
            ]],
            ['murder', 'Murder', IncidentCategory::Crime, Severity::High, [
                self::field('weapon_used', 'Weapon used', 'select', ['options' => self::options([
                    'firearm' => 'Firearm', 'knife_machete' => 'Knife/machete', 'blunt_object' => 'Blunt object',
                    'poison' => 'Poison', 'other' => 'Other', 'unknown' => 'Unknown',
                ])]),
                self::field('suspected_motive', 'Suspected motive', 'text'),
            ]],
            ['armed_robbery', 'Armed robbery', IncidentCategory::Crime, Severity::High, [
                self::field('target_type', 'Target', 'select', ['options' => self::options([
                    'residence' => 'Residence', 'business' => 'Business', 'bank' => 'Bank',
                    'highway_vehicle' => 'Highway/vehicle', 'pos_operator' => 'POS operator', 'other' => 'Other',
                ])]),
                self::field('weapons_used', 'Weapons used', 'multiselect', ['options' => $weapons]),
                self::field('estimated_loss_naira', 'Estimated loss (₦)', 'number', ['min' => 0]),
            ]],
            ['kidnapping', 'Kidnapping', IncidentCategory::Crime, Severity::Critical, [
                self::field('location_type', 'Location', 'select', ['options' => self::options([
                    'home' => 'Home', 'highway' => 'Highway', 'farm' => 'Farm', 'school' => 'School',
                    'place_of_worship' => 'Place of worship', 'workplace' => 'Workplace', 'other' => 'Other',
                ])]),
                self::field('ransom_demanded', 'Ransom demanded', 'boolean'),
                self::field('ransom_amount_naira', 'Ransom amount (₦)', 'number', ['min' => 0, 'help' => 'Leave blank if unknown']),
            ]],
            ['sea_piracy', 'Sea piracy', IncidentCategory::Crime, Severity::High, [
                self::field('vessel_name', 'Vessel name', 'text'),
                self::field('vessel_type', 'Vessel type', 'select', ['options' => self::options([
                    'cargo' => 'Cargo', 'tanker' => 'Tanker', 'fishing' => 'Fishing', 'passenger' => 'Passenger',
                    'supply_offshore' => 'Supply/offshore', 'other' => 'Other',
                ])]),
                self::field('waterway', 'Waterway', 'text'),
            ]],
            ['oil_bunkering', 'Oil bunkering', IncidentCategory::Crime, Severity::High, [
                self::field('activity_type', 'Activity', 'select', ['options' => self::options([
                    'pipeline_vandalism' => 'Pipeline vandalism', 'illegal_refinery' => 'Illegal refinery',
                    'illegal_loading_barge' => 'Illegal loading/barge', 'other' => 'Other',
                ])]),
                self::field('facility', 'Facility', 'text'),
                self::field('estimated_volume_litres', 'Estimated volume (litres)', 'number', ['min' => 0]),
                self::field('vehicles_or_barges_seized', 'Vehicles or barges seized', 'integer', ['min' => 0]),
            ]],
            ['religious_violence', 'Religious violence', IncidentCategory::ViolenceConflict, Severity::Critical, [
                self::field('trigger', 'Trigger', 'textarea'),
            ]],
            ['banditry', 'Banditry', IncidentCategory::ViolenceConflict, Severity::Critical, [
                self::field('estimated_attackers', 'Estimated attackers', 'integer', ['min' => 0]),
                self::field('livestock_rustled', 'Livestock rustled', 'integer', ['min' => 0]),
                self::field('villages_affected', 'Villages affected', 'integer', ['min' => 0]),
            ]],
            ['terrorist_attack', 'Terrorist attack', IncidentCategory::ViolenceConflict, Severity::Critical, [
                self::field('attack_method', 'Attack method', 'select', ['options' => self::options([
                    'ied' => 'IED', 'suicide_attack' => 'Suicide attack', 'armed_assault' => 'Armed assault',
                    'abduction' => 'Abduction', 'arson' => 'Arson', 'other' => 'Other',
                ])]),
                self::field('group_claiming', 'Group claiming responsibility', 'text'),
            ]],
            ['flood', 'Flood', IncidentCategory::DisasterEnvironmental, Severity::Medium, [
                self::field('cause', 'Cause', 'select', ['options' => self::options([
                    'heavy_rainfall' => 'Heavy rainfall', 'dam_release' => 'Dam release', 'river_overflow' => 'River overflow',
                    'blocked_drainage' => 'Blocked drainage', 'other' => 'Other',
                ])]),
                self::field('households_affected', 'Households affected', 'integer', ['min' => 0]),
                self::field('persons_displaced', 'Persons displaced', 'integer', ['min' => 0]),
                self::field('farmland_hectares', 'Farmland affected (hectares)', 'number', ['min' => 0]),
            ]],
            ['erosion', 'Erosion', IncidentCategory::DisasterEnvironmental, Severity::Medium, [
                self::field('erosion_type', 'Erosion type', 'select', ['options' => self::options([
                    'gully' => 'Gully', 'coastal' => 'Coastal', 'sheet_other' => 'Sheet/other',
                ])]),
                self::field('structures_threatened', 'Structures threatened', 'integer', ['min' => 0]),
                self::field('road_cut_off', 'Road cut off', 'boolean'),
            ]],
            ['security_agency_related_incident', 'Security agency-related incident', IncidentCategory::SecurityAgency, Severity::High, [
                self::field('involvement', 'Involvement', 'select', ['options' => self::options([
                    'attack_on_personnel' => 'Attack on personnel', 'attack_on_facility' => 'Attack on facility',
                    'misconduct_allegation' => 'Misconduct allegation', 'accidental_discharge' => 'Accidental discharge',
                    'inter_agency_clash' => 'Inter-agency clash', 'other' => 'Other',
                ])]),
            ]],
            ['other_security_incident', 'Other security incident', IncidentCategory::Other, Severity::Medium, []],
        ];

        $schemas = app(SchemaValidator::class);

        foreach ($types as $order => [$code, $name, $category, $severity, $schema]) {
            // The defaults must pass the same validator administrators use.
            $fields = $schemas->validate($schema, "incident_types.{$code}");

            $this->createMissing(IncidentType::class, ['code' => $code], [
                'name' => $name,
                'category' => $category,
                'default_severity' => $severity,
                'details_schema' => array_map(fn (FieldDefinition $field): array => $field->toArray(), $fields),
                'sort_order' => ($order + 1) * 10,
            ]);
        }
    }

    private function threatTypes(): void
    {
        $threats = [
            ['kidnapping', 'Kidnapping', IncidentCategory::Crime],
            ['banditry', 'Banditry', IncidentCategory::ViolenceConflict],
            ['cultism', 'Cultism', IncidentCategory::ViolenceConflict],
            ['communal_clash', 'Communal clash', IncidentCategory::ViolenceConflict],
            ['farmer_herder_conflict', 'Farmer-herder conflict', IncidentCategory::ViolenceConflict],
            ['terrorism', 'Terrorism', IncidentCategory::ViolenceConflict],
            ['armed_robbery', 'Armed robbery', IncidentCategory::Crime],
            ['sea_piracy', 'Sea piracy', IncidentCategory::Crime],
            ['oil_theft', 'Oil theft', IncidentCategory::Crime],
            ['political_violence', 'Political violence', IncidentCategory::ViolenceConflict],
            ['religious_tension', 'Religious tension', IncidentCategory::ViolenceConflict],
            ['flooding', 'Flooding', IncidentCategory::DisasterEnvironmental],
            ['erosion', 'Erosion', IncidentCategory::DisasterEnvironmental],
            ['other', 'Other', IncidentCategory::Other],
        ];

        foreach ($threats as $order => [$code, $name, $category]) {
            $this->createMissing(ThreatType::class, ['code' => $code], [
                'name' => $name,
                'category' => $category,
                'sort_order' => ($order + 1) * 10,
            ]);
        }
    }

    private function securityAgencies(): void
    {
        $agencies = [
            ['nigeria_police_force', 'Nigeria Police Force', 'NPF'],
            ['nigerian_army', 'Nigerian Army', 'NA'],
            ['nigerian_navy', 'Nigerian Navy', 'NN'],
            ['nigerian_air_force', 'Nigerian Air Force', 'NAF'],
            ['department_of_state_services', 'Department of State Services', 'DSS'],
            ['nigeria_security_and_civil_defence_corps', 'Nigeria Security and Civil Defence Corps', 'NSCDC'],
            ['federal_road_safety_corps', 'Federal Road Safety Corps', 'FRSC'],
            ['nigeria_customs_service', 'Nigeria Customs Service', 'NCS'],
            ['nigeria_immigration_service', 'Nigeria Immigration Service', 'NIS'],
            ['national_drug_law_enforcement_agency', 'National Drug Law Enforcement Agency', 'NDLEA'],
            ['economic_and_financial_crimes_commission', 'Economic and Financial Crimes Commission', 'EFCC'],
            ['federal_fire_service', 'Federal Fire Service', 'FFS'],
            ['national_emergency_management_agency', 'National Emergency Management Agency', 'NEMA'],
        ];

        foreach ($agencies as $order => [$code, $name, $short]) {
            $this->createMissing(SecurityAgency::class, ['code' => $code], [
                'name' => $name,
                'short_name' => $short,
                'agency_type' => AgencyType::Federal,
                'sort_order' => ($order + 1) * 10,
            ]);
        }
    }

    private function religions(): void
    {
        $religions = [
            ['christianity', 'Christianity', [
                'catholic' => 'Catholic', 'anglican' => 'Anglican', 'methodist' => 'Methodist',
                'baptist' => 'Baptist', 'pentecostal' => 'Pentecostal', 'other_christian' => 'Other Christian',
            ]],
            ['islam', 'Islam', ['sunni' => 'Sunni', 'shia' => 'Shia', 'other_islamic' => 'Other Islamic']],
            ['traditional_religion', 'Traditional religion', []],
            ['other', 'Other', []],
        ];

        foreach ($religions as $order => [$code, $name, $denominations]) {
            $religion = $this->createMissing(Religion::class, ['code' => $code], [
                'name' => $name,
                'sort_order' => ($order + 1) * 10,
            ]);

            $position = 0;
            foreach ($denominations as $denominationCode => $denominationName) {
                $this->createMissing(Denomination::class, ['religion_id' => $religion->id, 'code' => $denominationCode], [
                    'name' => $denominationName,
                    'sort_order' => ++$position * 10,
                ]);
            }
        }
    }

    private function propertyTypes(): void
    {
        $types = [
            'residential_building' => 'Residential building',
            'commercial_building' => 'Commercial building',
            'vehicle' => 'Vehicle',
            'government_facility' => 'Government facility',
            'place_of_worship' => 'Place of worship',
            'school' => 'School',
            'farmland_crops' => 'Farmland/crops',
            'livestock' => 'Livestock',
            'pipeline_oil_facility' => 'Pipeline/oil facility',
            'vessel' => 'Vessel',
            'telecom_infrastructure' => 'Telecom infrastructure',
            'other' => 'Other',
        ];

        $order = 0;
        foreach ($types as $code => $name) {
            $this->createMissing(PropertyType::class, ['code' => $code], [
                'name' => $name,
                'sort_order' => ++$order * 10,
            ]);
        }
    }

    private function organizationSectors(): void
    {
        $sectors = [
            'oil_and_gas' => 'Oil and gas',
            'mining' => 'Mining',
            'manufacturing' => 'Manufacturing',
            'agriculture' => 'Agriculture',
            'construction' => 'Construction',
            'telecommunications' => 'Telecommunications',
            'banking_and_finance' => 'Banking and finance',
            'transport_and_logistics' => 'Transport and logistics',
            'maritime_and_ports' => 'Maritime and ports',
            'markets_and_retail' => 'Markets and retail',
            'hospitality' => 'Hospitality',
            'education' => 'Education',
            'health' => 'Health',
            'media' => 'Media',
            'private_security' => 'Private security',
            'power_and_energy' => 'Power and energy',
            'government_and_public_services' => 'Government and public services',
            'non_profit' => 'Non-profit',
            'other' => 'Other',
        ];

        $order = 0;
        foreach ($sectors as $code => $name) {
            $this->createMissing(OrganizationSector::class, ['code' => $code], [
                'name' => $name,
                'sort_order' => ++$order * 10,
            ]);
        }
    }

    /**
     * Person role types (Phase 11A.1): the five system types (codes the
     * application relies on) and three examples the client may rename or
     * deactivate. The migration inserts the same rows; this only fills gaps.
     */
    private function personRoleTypes(): void
    {
        $types = [
            PersonRoleType::POLITICAL_LEADER => ['Political leader', 'Added automatically with a political office.', 10, true],
            PersonRoleType::TRADITIONAL_RULER => ['Traditional ruler', 'Required to be named traditional ruler of a community.', 20, true],
            PersonRoleType::RELIGIOUS_LEADER => ['Religious leader', 'Required to be named leader of a religious institution.', 30, true],
            PersonRoleType::COMMUNITY_LEADER => ['Community leader', null, 40, true],
            'youth_leader' => ['Youth leader', null, 50, false],
            'women_leader' => ['Women leader', null, 60, false],
            'market_leader' => ['Market or trade association leader', null, 70, false],
            PersonRoleType::PERSON_OF_INTEREST => ['Person of interest', 'Set only by the persons of interest module.', 90, true],
        ];

        foreach ($types as $code => [$name, $description, $order, $system]) {
            $this->createMissing(PersonRoleType::class, ['code' => $code], [
                'name' => $name,
                'description' => $description,
                'sort_order' => $order,
                'is_system' => $system,
            ]);
        }
    }

    /**
     * Offence types for arrests (Phase 11C), each mapped to a threat type
     * for analytics where one fits (editable by the client).
     */
    private function offenceTypes(): void
    {
        $offences = [
            ['murder', 'Murder', null],
            ['attempted_murder', 'Attempted murder', null],
            ['armed_robbery', 'Armed robbery', 'armed_robbery'],
            ['kidnapping', 'Kidnapping', 'kidnapping'],
            ['cultism', 'Cultism', 'cultism'],
            ['banditry', 'Banditry', 'banditry'],
            ['terrorism', 'Terrorism', 'terrorism'],
            ['unlawful_possession_of_firearms', 'Unlawful possession of firearms', null],
            ['illegal_arms_dealing', 'Illegal arms dealing', null],
            ['drug_trafficking', 'Drug trafficking', null],
            ['illegal_oil_bunkering', 'Illegal oil bunkering', 'oil_theft'],
            ['pipeline_vandalism', 'Pipeline vandalism', 'oil_theft'],
            ['sea_piracy', 'Sea piracy', 'sea_piracy'],
            ['sexual_offence', 'Sexual offence', null],
            ['assault', 'Assault', null],
            ['arson', 'Arson', null],
            ['fraud', 'Fraud', null],
            ['theft', 'Theft', null],
            ['criminal_conspiracy', 'Criminal conspiracy', null],
            ['public_disturbance', 'Public disturbance', null],
            ['other', 'Other', null],
        ];

        foreach ($offences as $order => [$code, $name, $threat]) {
            $this->createMissing(OffenceType::class, ['code' => $code], [
                'name' => $name,
                'threat_type_id' => $threat === null ? null : ThreatType::query()->where('code', $threat)->value('id'),
                'sort_order' => ($order + 1) * 10,
            ]);
        }
    }

    /**
     * Creates the entry only if no entry has these keys; never updates.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $class
     * @param  array<string, mixed>  $keys
     * @param  array<string, mixed>  $values
     * @return TModel
     */
    private function createMissing(string $class, array $keys, array $values)
    {
        $existing = $class::query()->where($keys)->first();

        if ($existing !== null) {
            return $existing;
        }

        $this->created++;

        return $class::query()->forceCreate([...$keys, ...$values]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private static function field(string $key, string $label, string $type, array $extra = []): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type, ...$extra];
    }

    /**
     * @param  array<string, string>  $options  value => label
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $options): array
    {
        return array_map(
            fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys($options),
            $options,
        );
    }
}
