<?php

namespace App\Http\Requests\Evidence;

use App\Enums\Classification;
use App\Models\IncidentEvidence;
use App\Models\User;
use App\Support\Incidents\ChildClassification;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An evidence item's metadata (Phase 5B). The capture place may be anywhere
 * (a photo can be taken outside the incident's LGA); the classification is
 * at least the incident's and within the editor's clearance.
 */
class EvidenceUpdateRequest extends FormRequest
{
    public const DATETIME = 'Y-m-d\TH:i';

    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();

        return $user->can('update', $this->evidence());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'captured_at' => ['nullable', 'date_format:'.self::DATETIME, function (string $attribute, mixed $value, Closure $fail): void {
                $at = self::time($value);

                if ($at !== null && $at->greaterThan(CarbonImmutable::now()->addMinute())) {
                    $fail('The capture time cannot be in the future.');
                }
            }],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $problem = ChildClassification::problem($this->user(), $this->evidence()->incident, Classification::tryFrom((string) $value), 'Evidence');

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
        ];
    }

    public function evidence(): IncidentEvidence
    {
        /** @var IncidentEvidence */
        return $this->route('evidence');
    }

    /**
     * @return array{title: string, description: string|null, captured_at: CarbonImmutable|null, latitude: float|null, longitude: float|null, classification: Classification}
     */
    public function values(): array
    {
        $description = trim((string) $this->validated('description'));
        $latitude = $this->validated('latitude');
        $longitude = $this->validated('longitude');

        return [
            'title' => trim((string) $this->validated('title')),
            'description' => $description === '' ? null : $description,
            'captured_at' => self::time($this->validated('captured_at')),
            'latitude' => is_numeric($latitude) ? (float) $latitude : null,
            'longitude' => is_numeric($longitude) ? (float) $longitude : null,
            'classification' => Classification::from((string) $this->validated('classification')),
        ];
    }

    public static function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat(self::DATETIME, $value, config('app.timezone'))?->startOfMinute();
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
