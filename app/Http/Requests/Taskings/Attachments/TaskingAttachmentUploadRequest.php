<?php

namespace App\Http\Requests\Taskings\Attachments;

use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Models\Tasking;
use App\Models\TaskingAttachment;
use App\Models\TaskingRecipient;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Evidence\EvidenceFileInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * One photo, video or document for a tasking draft ({tasking}) or for an
 * LGA's report back ({recipient}) (Phase 11H). Judged by its content like
 * evidence (EvidenceFileInspector, same size limits): images, videos and
 * documents (PDF, Word, Excel, plain text); no audio. At most
 * buffalo.taskings.max_attachments on the tasking, and as many on each
 * report back. The classification defaults to the tasking's, is never
 * below it, and is within the uploader's clearance.
 */
class TaskingAttachmentUploadRequest extends FormRequest
{
    /** @var array{type: EvidenceType, mime: string, extension: string}|null */
    private ?array $detected = null;

    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();
        $recipient = $this->recipient();

        return $recipient !== null
            ? $user->can('uploadToResponse', [TaskingAttachment::class, $recipient])
            : $user->can('upload', [TaskingAttachment::class, $this->tasking()]);
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

                $max = (int) config('buffalo.taskings.max_attachments');
                $recipient = $this->recipient();
                $count = TaskingAttachment::query()->where('tasking_id', $this->tasking()->id)
                    ->when($recipient, fn ($q) => $q->where('tasking_recipient_id', $recipient?->id), fn ($q) => $q->whereNull('tasking_recipient_id'))
                    ->count();

                if ($count >= $max) {
                    $fail("At most {$max} files can be attached here.");

                    return;
                }

                $result = app(EvidenceFileInspector::class)->inspect((string) $value->getRealPath(), $value->getClientOriginalName());

                if (is_string($result)) {
                    $fail($result);

                    return;
                }

                if ($result['type'] === EvidenceType::Audio) {
                    $fail('Attach photos, videos or documents only.');

                    return;
                }

                $this->detected = $result;
            }],
            'caption' => ['nullable', 'string', 'max:200'],
            'classification' => ['nullable', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $problem = self::classificationProblem($this->user(), $this->tasking(), Classification::tryFrom((string) $value));

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
        ];
    }

    /** Why a level cannot be given to an attachment of this tasking, if it cannot. */
    public static function classificationProblem(?User $user, Tasking $tasking, ?Classification $level): ?string
    {
        if ($level === null || $user === null) {
            return null;
        }

        if ($level->rank() < $tasking->classificationLevel()->rank()) {
            return "An attachment cannot be classified below its tasking ({$tasking->classificationLevel()->label()}).";
        }

        if (! app(ClassificationGate::class)->canAssign($user, $level)) {
            return 'You cannot classify a record above your own clearance.';
        }

        return null;
    }

    public function tasking(): Tasking
    {
        $recipient = $this->recipient();

        if ($recipient !== null) {
            $tasking = $recipient->tasking;

            if ($tasking === null) {
                abort(404);
            }

            return $tasking;
        }

        /** @var Tasking */
        return $this->route('tasking');
    }

    public function recipient(): ?TaskingRecipient
    {
        $recipient = $this->route('recipient');

        return $recipient instanceof TaskingRecipient ? $recipient : null;
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

    public function caption(): ?string
    {
        $caption = trim((string) $this->validated('caption'));

        return $caption === '' ? null : $caption;
    }

    public function classification(): Classification
    {
        return Classification::tryFrom((string) $this->validated('classification')) ?? $this->tasking()->classificationLevel();
    }
}
