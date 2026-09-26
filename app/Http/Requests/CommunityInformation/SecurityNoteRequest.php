<?php

namespace App\Http\Requests\CommunityInformation;

use App\Enums\Classification;
use App\Enums\SecurityNoteCategory;
use App\Enums\SecurityNoteStatus;
use App\Models\Community;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Create or edit a community security note. The classification must be one
 * the user may assign (ClassificationGate::canAssign): never above what
 * they could see. Status is set on create; later changes use
 * SecurityNoteStatusRequest.
 */
class SecurityNoteRequest extends LgaRecordRequest
{
    protected function recordParameter(): string
    {
        return 'note';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->record() === null;

        return [
            'community' => ['required', 'string', $this->communityOfLga()],
            'category' => ['required', Rule::enum(SecurityNoteCategory::class)],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['required', 'string', 'max:20000'],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $level = Classification::tryFrom((string) $value);
                /** @var User $user */
                $user = $this->user();

                if ($level !== null && ! app(ClassificationGate::class)->canAssign($user, $level)) {
                    $fail('You cannot classify a record above your own clearance.');
                }
            }],
            'observed_at' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'status' => $creating ? ['required', Rule::enum(SecurityNoteStatus::class)] : ['prohibited'],
            'resolved_at' => $creating
                ? ['nullable', 'required_if:status,resolved', 'date_format:Y-m-d', 'before_or_equal:today', 'after_or_equal:observed_at']
                : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.prohibited' => 'Change the status with the status action.',
            'resolved_at.required_if' => 'Give the date the matter was resolved.',
        ];
    }

    /**
     * @return array{community: Community, category: string, title: string, description: string, classification: string, observed_at: string, status: string, resolved_at: string|null}
     */
    public function values(): array
    {
        $data = $this->validated();
        /** @var Community $community */
        $community = $this->community();

        return [
            'community' => $community,
            'category' => (string) $data['category'],
            'title' => (string) $data['title'],
            'description' => (string) $data['description'],
            'classification' => (string) $data['classification'],
            'observed_at' => (string) $data['observed_at'],
            'status' => (string) ($data['status'] ?? SecurityNoteStatus::Active->value),
            'resolved_at' => $data['resolved_at'] ?? null,
        ];
    }
}
