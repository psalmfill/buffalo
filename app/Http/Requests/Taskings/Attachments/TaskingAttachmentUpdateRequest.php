<?php

namespace App\Http\Requests\Taskings\Attachments;

use App\Enums\Classification;
use App\Http\Requests\Evidence\EvidenceUpdateRequest;
use App\Models\Tasking;
use App\Models\TaskingAttachment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An attachment's caption, capture time and place, and classification
 * (Phase 11H), while its tasking is a draft, or its report back is not yet completed.
 */
class TaskingAttachmentUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();

        return $user->can('update', $this->attachment());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'caption' => ['nullable', 'string', 'max:200'],
            'captured_at' => ['nullable', 'date_format:'.EvidenceUpdateRequest::DATETIME, function (string $attribute, mixed $value, Closure $fail): void {
                $at = EvidenceUpdateRequest::time($value);

                if ($at !== null && $at->greaterThan(CarbonImmutable::now()->addMinute())) {
                    $fail('The capture time cannot be in the future.');
                }
            }],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                /** @var Tasking $tasking */
                $tasking = $this->attachment()->tasking;
                $problem = TaskingAttachmentUploadRequest::classificationProblem($this->user(), $tasking, Classification::tryFrom((string) $value));

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
        ];
    }

    public function attachment(): TaskingAttachment
    {
        /** @var TaskingAttachment */
        return $this->route('attachment');
    }

    /**
     * @return array{caption: string|null, captured_at: CarbonImmutable|null, latitude: float|null, longitude: float|null, classification: Classification}
     */
    public function values(): array
    {
        $caption = trim((string) $this->validated('caption'));
        $latitude = $this->validated('latitude');
        $longitude = $this->validated('longitude');

        return [
            'caption' => $caption === '' ? null : $caption,
            'captured_at' => EvidenceUpdateRequest::time($this->validated('captured_at')),
            'latitude' => is_numeric($latitude) ? (float) $latitude : null,
            'longitude' => is_numeric($longitude) ? (float) $longitude : null,
            'classification' => Classification::from((string) $this->validated('classification')),
        ];
    }
}
