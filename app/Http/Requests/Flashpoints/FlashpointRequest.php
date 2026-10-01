<?php

namespace App\Http\Requests\Flashpoints;

use App\Enums\Classification;
use App\Enums\ConcernLevel;
use App\Enums\FlashpointKind;
use App\Enums\FlashpointStatus;
use App\Models\Community;
use App\Models\Flashpoint;
use App\Models\Lga;
use App\Models\ThreatType;
use App\Models\User;
use App\Rules\ActiveOption;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\CommunityRules;
use App\Support\LocalGovernment\Nigeria;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create a flashpoint (with its first assessment) or edit one (Phase 6A).
 * The point must lie in Nigeria and inside the chosen LGA's boundary; the
 * radius is 100 m to 50 km. The concern level, status and recommended
 * action are given only on creation: afterwards they change through an
 * assessment. Authorisation is in the controller.
 */
class FlashpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function flashpoint(): ?Flashpoint
    {
        $flashpoint = $this->route('flashpoint');

        return $flashpoint instanceof Flashpoint ? $flashpoint : null;
    }

    /** The chosen LGA, within the user's jurisdiction (on edit, the current one when unchanged). */
    public function lga(): ?Lga
    {
        $current = $this->flashpoint();
        $ulid = $this->input('lga');

        if ($current !== null && (! is_string($ulid) || $ulid === $current->lga->ulid)) {
            return $current->lga;
        }

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

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->flashpoint() === null;

        return [
            'lga' => [$creating ? 'required' : 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() === null) {
                    $fail('Choose an LGA within your jurisdiction.');
                }
            }],
            'kind' => ['required', Rule::enum(FlashpointKind::class)],
            'name' => ['required', 'string', 'max:200'],
            'threat_type' => ['required', 'string', new ActiveOption(ThreatType::class, $this->flashpoint()?->threatType->ulid)],
            'community' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() !== null && $this->community() === null) {
                    $fail('The community must be a community of the chosen LGA.');
                }
            }],
            'latitude' => ['required', 'numeric', 'between:'.Nigeria::MIN_LATITUDE.','.Nigeria::MAX_LATITUDE],
            'longitude' => ['required', 'numeric', 'between:'.Nigeria::MIN_LONGITUDE.','.Nigeria::MAX_LONGITUDE, function (string $attribute, mixed $value, Closure $fail): void {
                $latitude = $this->input('latitude');
                $lga = $this->lga();

                if ($lga !== null && is_numeric($value) && is_numeric($latitude)) {
                    $error = app(CommunityRules::class)->locationError($lga, (float) $latitude, (float) $value);

                    if ($error !== null) {
                        $fail($error);
                    }
                }
            }],
            'radius_meters' => ['required', 'integer', 'between:100,50000'],
            'description' => ['required', 'string', 'max:10000'],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $level = Classification::tryFrom((string) $value);
                /** @var User $user */
                $user = $this->user();

                if ($level !== null && ! app(ClassificationGate::class)->canAssign($user, $level)) {
                    $fail('You cannot classify a record above your own clearance.');
                }
            }],
            // A suggested blackspot being accepted (creation only, Phase 6C).
            'suggestion' => $creating ? ['nullable', 'string', 'size:26'] : ['prohibited'],
            // The first assessment (creation only).
            'concern_level' => $creating ? ['required', Rule::enum(ConcernLevel::class)] : ['prohibited'],
            'status' => $creating ? ['required', Rule::enum(FlashpointStatus::class)] : ['prohibited'],
            'assessment' => $creating ? ['required', 'string', 'max:10000'] : ['prohibited'],
            'recommended_action' => $creating ? ['nullable', 'string', 'max:5000'] : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'radius_meters.between' => 'The radius must be between 100 m and 50 km.',
            'concern_level.prohibited' => 'Change the concern level by recording an assessment.',
            'status.prohibited' => 'Change the status by recording an assessment.',
            'assessment.prohibited' => 'Record assessments from the flashpoint page.',
            'recommended_action.prohibited' => 'Change the recommended action by recording an assessment.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->validated();

        return [
            'kind' => (string) $data['kind'],
            'name' => trim((string) $data['name']),
            'threat_type_id' => ThreatType::query()->where('ulid', $data['threat_type'])->value('id'),
            'community_id' => $this->community()?->id,
            'latitude' => (float) $data['latitude'],
            'longitude' => (float) $data['longitude'],
            'radius_meters' => (int) $data['radius_meters'],
            'description' => trim((string) $data['description']),
            'classification' => (string) $data['classification'],
        ];
    }

    /**
     * @return array{concern_level: string, status: string, assessment: string, recommended_action: string|null}
     */
    public function firstAssessment(): array
    {
        $data = $this->validated();

        return AssessmentRequest::normalise($data);
    }
}
