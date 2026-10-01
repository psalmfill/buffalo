<?php

namespace App\Http\Requests\Threats;

use App\Enums\Classification;
use App\Enums\ThreatCredibility;
use App\Enums\ThreatGroupRole;
use App\Enums\ThreatSourceType;
use App\Enums\ThreatUrgency;
use App\Models\Community;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\Lga;
use App\Models\ThreatReport;
use App\Models\ThreatType;
use App\Models\User;
use App\Rules\ActiveOption;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\CommunityRules;
use App\Support\LocalGovernment\Nigeria;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Record a threat report (with initial flashpoint and group links) or edit
 * one (Phase 6B). Times are local (Africa/Lagos) "Y-m-dTH:i".
 *
 * - Expiry (creation only; later through Extend): given, or the expected
 *   end, or `default_expiry_days` from now; always in the future and at
 *   most `max_expiry_days` ahead.
 * - A radius needs a point; the point must lie inside the LGA.
 * - On edit, a change of credibility or urgency needs a comment.
 *
 * Authorisation is in the controller.
 */
class ThreatReportRequest extends FormRequest
{
    public const DATETIME = 'Y-m-d\TH:i';

    public function authorize(): bool
    {
        return true;
    }

    public function threat(): ?ThreatReport
    {
        $threat = $this->route('threat');

        return $threat instanceof ThreatReport ? $threat : null;
    }

    public function lga(): ?Lga
    {
        $current = $this->threat();
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
        $creating = $this->threat() === null;

        return [
            'lga' => [$creating ? 'required' : 'nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() === null) {
                    $fail('Choose an LGA within your jurisdiction.');
                }
            }],
            'threat_type' => ['required', 'string', new ActiveOption(ThreatType::class, $this->threat()?->threatType->ulid)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:20000'],
            'credibility' => ['required', Rule::enum(ThreatCredibility::class)],
            'urgency' => ['required', Rule::enum(ThreatUrgency::class)],
            'source_type' => ['required', Rule::enum(ThreatSourceType::class)],
            'source_notes' => ['nullable', 'string', 'max:5000'],
            'community' => ['nullable', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() !== null && $this->community() === null) {
                    $fail('The community must be a community of the chosen LGA.');
                }
            }],
            'latitude' => ['nullable', 'required_with:longitude,radius_meters', 'numeric', 'between:'.Nigeria::MIN_LATITUDE.','.Nigeria::MAX_LATITUDE],
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
            'radius_meters' => ['nullable', 'required_with:latitude', 'integer', 'between:100,50000'],
            'target_description' => ['nullable', 'string', 'max:2000'],
            'expected_from' => ['nullable', 'date_format:'.self::DATETIME],
            'expected_until' => ['nullable', 'date_format:'.self::DATETIME, 'after_or_equal:expected_from'],
            // The effective expiry is checked in after().
            'expires_at' => $creating ? ['nullable', 'date_format:'.self::DATETIME] : ['prohibited'],
            'recommended_action' => ['nullable', 'string', 'max:5000'],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $level = Classification::tryFrom((string) $value);
                /** @var User $user */
                $user = $this->user();

                if ($level !== null && ! app(ClassificationGate::class)->canAssign($user, $level)) {
                    $fail('You cannot classify a record above your own clearance.');
                }
            }],
            'change_comment' => [$creating ? 'prohibited' : 'nullable', 'string', 'max:2000'],
            'flashpoints' => [$creating ? 'nullable' : 'prohibited', 'array', 'max:20'],
            'flashpoints.*' => ['string', 'distinct', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || Flashpoint::query()->where('ulid', $value)->doesntExist()) {
                    $fail('Choose flashpoints you can see.');
                }
            }],
            'groups' => [$creating ? 'nullable' : 'prohibited', 'array', 'max:20'],
            'groups.*.group' => ['required', 'string', 'distinct', function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || CriminalGroup::query()->where('ulid', $value)->doesntExist()) {
                    $fail('Choose criminal groups you can see.');
                }
            }],
            'groups.*.role' => ['required', Rule::enum(ThreatGroupRole::class)],
        ];
    }

    /**
     * @return array<int, Closure>
     */
    public function after(): array
    {
        return [function ($validator): void {
            $current = $this->threat();

            if ($current !== null) {
                $changed = $this->input('credibility') !== $current->credibility->value || $this->input('urgency') !== $current->urgency->value;

                if ($changed && trim((string) $this->input('change_comment')) === '') {
                    $validator->errors()->add('change_comment', 'Explain why the credibility or urgency changed.');
                }

                return;
            }

            if ($validator->errors()->hasAny(['expires_at', 'expected_until'])) {
                return;
            }

            $error = self::expiryError($this->expiresAt());

            if ($error !== null) {
                $validator->errors()->add('expires_at', $error);
            }
        }];
    }

    /** Why an expiry is not allowed (null if it is): future, within the maximum. */
    public static function expiryError(CarbonImmutable $expiresAt): ?string
    {
        $max = (int) config('buffalo.threats.max_expiry_days');

        if ($expiresAt->lessThanOrEqualTo(CarbonImmutable::now())) {
            return 'The expiry must be in the future.';
        }

        if ($expiresAt->greaterThan(CarbonImmutable::now()->addDays($max))) {
            return "The expiry can be at most {$max} days ahead.";
        }

        return null;
    }

    /** The effective expiry on creation. */
    public function expiresAt(): CarbonImmutable
    {
        $given = $this->input('expires_at');
        $until = $this->input('expected_until');

        return match (true) {
            is_string($given) && $given !== '' => self::time($given),
            is_string($until) && $until !== '' => self::time($until),
            default => CarbonImmutable::now()->addDays((int) config('buffalo.threats.default_expiry_days')),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $data = $this->validated();

        return [
            'threat_type_id' => ThreatType::query()->where('ulid', $data['threat_type'])->value('id'),
            'title' => trim((string) $data['title']),
            'description' => trim((string) $data['description']),
            'credibility' => (string) $data['credibility'],
            'urgency' => (string) $data['urgency'],
            'source_type' => (string) $data['source_type'],
            'source_notes' => self::text($data['source_notes'] ?? null),
            'community_id' => $this->community()?->id,
            'latitude' => isset($data['latitude']) ? (float) $data['latitude'] : null,
            'longitude' => isset($data['longitude']) ? (float) $data['longitude'] : null,
            'radius_meters' => isset($data['radius_meters']) ? (int) $data['radius_meters'] : null,
            'target_description' => self::text($data['target_description'] ?? null),
            'expected_from' => isset($data['expected_from']) ? self::time((string) $data['expected_from']) : null,
            'expected_until' => isset($data['expected_until']) ? self::time((string) $data['expected_until']) : null,
            'expires_at' => $this->threat() === null ? $this->expiresAt() : null,
            'recommended_action' => self::text($data['recommended_action'] ?? null),
            'classification' => (string) $data['classification'],
        ];
    }

    public function comment(): ?string
    {
        return self::text($this->validated('change_comment'));
    }

    /**
     * @return list<Flashpoint>
     */
    public function flashpoints(): array
    {
        $ulids = (array) ($this->validated('flashpoints') ?? []);

        return array_values(Flashpoint::query()->whereIn('ulid', $ulids)->get()->all());
    }

    /**
     * @return list<array{group: CriminalGroup, role: string}>
     */
    public function groups(): array
    {
        $rows = (array) ($this->validated('groups') ?? []);
        $groups = CriminalGroup::query()->whereIn('ulid', array_column($rows, 'group'))->get()->keyBy('ulid');

        return array_values(array_filter(array_map(fn (array $row) => $groups->has($row['group'])
            ? ['group' => $groups->get($row['group']), 'role' => (string) $row['role']]
            : null, $rows)));
    }

    private static function time(string $value): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat(self::DATETIME, $value, (string) config('app.timezone')) ?: CarbonImmutable::now();
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
