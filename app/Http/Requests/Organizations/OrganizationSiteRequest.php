<?php

namespace App\Http\Requests\Organizations;

use App\Enums\Classification;
use App\Enums\SecurityRelevance;
use App\Enums\SiteStatus;
use App\Enums\SiteType;
use App\Models\Community;
use App\Models\Lga;
use App\Models\Organization;
use App\Models\OrganizationSite;
use App\Models\User;
use App\Rules\RichTextRule;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\CommunityRules;
use App\Support\LocalGovernment\Nigeria;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Create a site of an organization (LGA chosen once, within the user's
 * jurisdiction) or edit one. At most one head office per organization. The
 * security provider is another directory entry; a provider outside the
 * private security sector is allowed (the controller warns).
 * Authorisation is in the controller.
 */
class OrganizationSiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function site(): ?OrganizationSite
    {
        $site = $this->route('site');

        return $site instanceof OrganizationSite ? $site : null;
    }

    public function organization(): ?Organization
    {
        $site = $this->site();

        if ($site !== null) {
            return $site->organization;
        }

        $organization = $this->route('organization');

        return $organization instanceof Organization ? $organization : null;
    }

    public function lga(): ?Lga
    {
        $site = $this->site();

        if ($site !== null) {
            return $site->lga;
        }

        $ulid = $this->input('lga');

        if (! is_string($ulid)) {
            return null;
        }

        /** @var User $user */
        $user = $this->user();

        return app(JurisdictionOptions::class)->lgaQuery($user)->active()->where('ulid', $ulid)->first();
    }

    public function community(): ?Community
    {
        $lga = $this->lga();

        return $lga === null ? null : app(CommunityRules::class)->communityOf($lga, $this->input('community'));
    }

    public function provider(): ?Organization
    {
        $ulid = $this->input('security_provider');

        return is_string($ulid) ? Organization::query()->where('ulid', $ulid)->first() : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editing = $this->site() !== null;

        return [
            'lga' => $editing ? ['prohibited'] : ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() === null) {
                    $fail('Choose an LGA within your jurisdiction.');
                }
            }],
            'name' => ['required', 'string', 'max:255'],
            'site_type' => ['required', Rule::enum(SiteType::class), function (string $attribute, mixed $value, Closure $fail): void {
                $organization = $this->organization();

                if ($value !== SiteType::HeadOffice->value || $organization === null) {
                    return;
                }

                // Every site of the organization, wherever it is: ids only.
                $taken = DB::table('organization_sites')
                    ->where('organization_id', $organization->id)
                    ->where('site_type', SiteType::HeadOffice->value)
                    ->whereNull('deleted_at')
                    ->when($this->site(), fn ($q, OrganizationSite $site) => $q->where('id', '<>', $site->id))
                    ->exists();

                if ($taken) {
                    $fail('This organization already has a head office.');
                }
            }],
            'community' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() !== null && $this->community() === null) {
                    $fail('The community must be a community of the site\'s LGA.');
                }
            }],
            'address' => ['nullable', 'string', 'max:2000'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:'.Nigeria::MIN_LATITUDE.','.Nigeria::MAX_LATITUDE],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:'.Nigeria::MIN_LONGITUDE.','.Nigeria::MAX_LONGITUDE, function (string $attribute, mixed $value, Closure $fail): void {
                $latitude = $this->input('latitude');
                $lga = $this->lga();

                if ($lga !== null && is_numeric($value) && is_numeric($latitude)) {
                    $error = app(CommunityRules::class)->locationError($lga, (float) $latitude, (float) $value);

                    if ($error !== null) {
                        $fail($error);
                    }
                }
            }],
            'employees_estimate' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'security_relevance' => ['required', Rule::enum(SecurityRelevance::class)],
            'has_private_security' => ['required', 'boolean'],
            'security_provider' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                $provider = $this->provider();

                if ($provider === null) {
                    $fail('Choose a security provider from the organization directory.');
                } elseif ($provider->id === $this->organization()?->id) {
                    $fail('An organization cannot be its own security provider.');
                }
            }],
            'security_notes' => ['nullable', new RichTextRule(20000)],
            'status' => ['required', Rule::enum(SiteStatus::class)],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $level = Classification::tryFrom((string) $value);
                /** @var User $user */
                $user = $this->user();

                if ($level !== null && ! app(ClassificationGate::class)->canAssign($user, $level)) {
                    $fail('You cannot classify a record above your own clearance.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['lga.prohibited' => 'A site\'s LGA cannot be changed.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->validated();
        $text = fn (string $key): ?string => isset($data[$key]) && trim((string) $data[$key]) !== '' ? trim((string) $data[$key]) : null;

        return [
            'community_id' => $this->community()?->id,
            'name' => trim((string) $data['name']),
            'site_type' => (string) $data['site_type'],
            'address' => $text('address'),
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'employees_estimate' => isset($data['employees_estimate']) ? (int) $data['employees_estimate'] : null,
            'security_relevance' => (string) $data['security_relevance'],
            'has_private_security' => (bool) $data['has_private_security'],
            'security_provider_organization_id' => $this->provider()?->id,
            'security_notes' => $text('security_notes'),
            'status' => (string) $data['status'],
            'classification' => (string) $data['classification'],
        ];
    }
}
