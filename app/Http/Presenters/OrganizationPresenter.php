<?php

namespace App\Http\Presenters;

use App\Enums\Classification;
use App\Enums\OrganizationStatus;
use App\Enums\OrganizationType;
use App\Enums\SecurityArrangement;
use App\Enums\SecurityRelevance;
use App\Enums\SiteStatus;
use App\Enums\SiteType;
use App\Models\Organization;
use App\Models\OrganizationContact;
use App\Models\OrganizationSector;
use App\Models\OrganizationSite;
use App\Models\OrganizationSiteFormation;
use App\Models\User;

/**
 * Organizations, sites and contacts for the browser (Phase 4B): ULIDs and
 * labels, never ids. Contact phone and email appear only in contact()
 * (whose callers log the view).
 */
final class OrganizationPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(Organization $organization): array
    {
        return [
            'ulid' => $organization->ulid,
            'name' => $organization->name,
            'other_names' => $organization->other_names,
            'type' => ['value' => $organization->type->value, 'label' => $organization->type->label()],
            'sector' => ['ulid' => $organization->sector->ulid, 'name' => $organization->sector->name],
            'status' => ['value' => $organization->status->value, 'label' => $organization->status->label()],
            'rc_number' => $organization->rc_number,
            // Sites the VIEWER can see (withCount through the site scopes).
            'visible_sites' => (int) ($organization->getAttribute('sites_count') ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function detail(Organization $organization): array
    {
        return [
            ...self::row($organization),
            'website' => $organization->website,
            'description' => $organization->description,
            'is_private_security' => $organization->sector->code === 'private_security',
            'created_at' => $organization->created_at?->toIso8601String(),
            'updated_at' => $organization->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function site(OrganizationSite $site): array
    {
        return [
            'ulid' => $site->ulid,
            'name' => $site->name,
            'organization' => ['ulid' => $site->organization->ulid, 'name' => $site->organization->name],
            'site_type' => ['value' => $site->site_type->value, 'label' => $site->site_type->label()],
            'security_relevance' => ['value' => $site->security_relevance->value, 'label' => $site->security_relevance->label()],
            'status' => ['value' => $site->status->value, 'label' => $site->status->label()],
            'classification' => ['value' => $site->classificationLevel()->value, 'label' => $site->classificationLevel()->label()],
            'lga' => ['ulid' => $site->lga->ulid, 'name' => $site->lga->name],
            'state' => ['ulid' => $site->state->ulid, 'name' => $site->state->name],
            'has_private_security' => $site->has_private_security,
            'employees_estimate' => $site->employees_estimate,
            'latitude' => $site->getAttribute('latitude') === null ? null : (float) $site->getAttribute('latitude'),
            'longitude' => $site->getAttribute('longitude') === null ? null : (float) $site->getAttribute('longitude'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function siteDetail(OrganizationSite $site): array
    {
        $provider = $site->securityProvider;

        return [
            ...self::site($site),
            'community' => $site->community === null ? null : ['ulid' => $site->community->ulid, 'name' => $site->community->name],
            'address' => $site->address,
            'security_notes' => $site->security_notes,
            'security_provider' => $provider === null ? null : [
                'ulid' => $provider->ulid,
                'name' => $provider->name,
                // A warning, not an error: the provider should be a private security firm.
                'is_private_security' => $provider->sector->code === 'private_security',
            ],
            'created_at' => $site->created_at?->toIso8601String(),
            'updated_at' => $site->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Contact details, including the decrypted phone and email. The caller
     * logs the view. The linked person is shown only to viewers who can see
     * them (the relation goes through the person's scopes).
     *
     * @return array<string, mixed>
     */
    public static function contact(OrganizationContact $contact, User $viewer): array
    {
        $person = $contact->person;

        return [
            'ulid' => $contact->ulid,
            'name' => $contact->name,
            'position' => $contact->position,
            'phone' => $contact->phone,
            'email' => $contact->email,
            'is_management' => $contact->is_management,
            'is_security_contact' => $contact->is_security_contact,
            'is_primary' => $contact->is_primary,
            'notes' => $contact->notes,
            'person' => $person !== null && $viewer->can('view', $person) ? ['ulid' => $person->ulid, 'name' => $person->name] : null,
            // A person is linked that the viewer cannot see: say nothing more.
            'person_hidden' => $contact->person_id !== null && ($person === null || ! $viewer->can('view', $person)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function formationLink(OrganizationSiteFormation $link): array
    {
        return [
            'ulid' => $link->ulid,
            'formation' => AgencyFormationPresenter::row($link->formation),
            'arrangement' => ['value' => $link->arrangement->value, 'label' => $link->arrangement->label()],
            'notes' => $link->notes,
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    public static function options(): array
    {
        $option = fn (OrganizationType|OrganizationStatus|SiteType|SiteStatus|SecurityRelevance|SecurityArrangement|Classification $c) => ['value' => $c->value, 'label' => $c->label()];

        return [
            'types' => array_map($option, OrganizationType::cases()),
            'statuses' => array_map($option, OrganizationStatus::cases()),
            'siteTypes' => array_map($option, SiteType::cases()),
            'siteStatuses' => array_map($option, SiteStatus::cases()),
            'relevance' => array_map($option, SecurityRelevance::cases()),
            'arrangements' => array_map($option, SecurityArrangement::cases()),
            'classifications' => array_map($option, Classification::cases()),
        ];
    }

    /**
     * Sectors to choose from: active ones, plus $keep (a current value).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function sectorOptions(?OrganizationSector $keep = null): array
    {
        return array_values(OrganizationSector::query()
            ->where(fn ($q) => $q->where('is_active', true)->when($keep, fn ($w) => $w->orWhere('id', $keep?->id)))
            ->orderBy('sort_order')->orderBy('name')
            ->get()
            ->map(fn (OrganizationSector $s) => ['value' => $s->ulid, 'label' => $s->name])
            ->all());
    }
}
