<?php

namespace App\Http\Requests\Evidence;

use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Models\Incident;
use App\Models\IncidentEvidence;
use App\Models\User;
use App\Support\Evidence\EvidenceFileInspector;
use App\Support\Incidents\ChildClassification;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * One evidence file with its title, description and classification
 * (Phase 5B). The file is judged by its content (EvidenceFileInspector);
 * the classification must be at least the incident's and within the
 * uploader's clearance.
 */
class EvidenceUploadRequest extends FormRequest
{
    /** @var array{type: EvidenceType, mime: string, extension: string}|null */
    private ?array $detected = null;

    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();

        return $user->can('upload', [IncidentEvidence::class, $this->incident()]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', function (string $attribute, mixed $value, Closure $fail): void {
                if (! $value instanceof UploadedFile || ! $value->isValid()) {
                    $fail('The file could not be uploaded.');

                    return;
                }

                $result = app(EvidenceFileInspector::class)->inspect((string) $value->getRealPath(), $value->getClientOriginalName());

                if (is_string($result)) {
                    $fail($result);

                    return;
                }

                $this->detected = $result;
            }],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $problem = ChildClassification::problem($this->user(), $this->incident(), Classification::tryFrom((string) $value), 'Evidence');

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
        ];
    }

    public function incident(): Incident
    {
        /** @var Incident */
        return $this->route('incident');
    }

    /**
     * @return array{type: EvidenceType, mime: string, extension: string}
     */
    public function detected(): array
    {
        if ($this->detected === null) {
            throw new LogicException('The file has not been validated.');
        }

        return $this->detected;
    }

    /**
     * @return array{title: string, description: string|null, classification: Classification}
     */
    public function values(): array
    {
        $description = trim((string) $this->validated('description'));

        return [
            'title' => trim((string) $this->validated('title')),
            'description' => $description === '' ? null : $description,
            'classification' => Classification::from((string) $this->validated('classification')),
        ];
    }
}
