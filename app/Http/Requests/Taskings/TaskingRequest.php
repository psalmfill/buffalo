<?php

namespace App\Http\Requests\Taskings;

use App\Actions\Taskings\SendTasking;
use App\Enums\Classification;
use App\Enums\TaskingPriority;
use App\Http\Requests\Evidence\EvidenceUpdateRequest;
use App\Models\Lga;
use App\Models\Tasking;
use App\Models\User;
use App\Rules\RichTextRule;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionOptions;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Saving a tasking draft, or sending it (`send`), from the one form
 * (Phase 11H). The recipient LGAs come as ULIDs from the picker and are
 * resolved here against the sender's own state: active LGAs of that state
 * only (JurisdictionOptions); a forged LGA of another state is refused.
 * SendTasking checks them again inside its transaction.
 *
 * Sending also needs: some body text (plain-text limit
 * buffalo.taskings.max_characters), at least one LGA, a due date in the
 * future (if given), and a classification LGA chiefs are cleared for.
 */
class TaskingRequest extends FormRequest
{
    /** @var Collection<int, Lga>|null */
    private ?Collection $lgas = null;

    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();
        $tasking = $this->route('tasking');

        // Before validation, so nobody else learns which LGAs are valid.
        return $tasking instanceof Tasking ? $user->can('update', $tasking) : $user->can('create', Tasking::class);
    }

    public function sending(): bool
    {
        return $this->boolean('send');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $sending = $this->sending();

        return [
            'send' => ['sometimes', 'boolean'],
            'title' => ['nullable', 'string', 'max:200'],
            'body' => [$sending ? 'required' : 'nullable', new RichTextRule((int) config('buffalo.taskings.max_characters'), required: $sending)],
            'priority' => ['required', Rule::enum(TaskingPriority::class)],
            'response_due_at' => ['nullable', 'date_format:'.EvidenceUpdateRequest::DATETIME, function (string $attribute, mixed $value, Closure $fail) use ($sending): void {
                $at = EvidenceUpdateRequest::time($value);

                if ($sending && $at !== null && $at->lessThanOrEqualTo(CarbonImmutable::now())) {
                    $fail('The response date must be in the future.');
                }
            }],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail) use ($sending): void {
                $level = Classification::tryFrom((string) $value);
                /** @var User $user */
                $user = $this->user();

                if ($level === null) {
                    return;
                }

                if (! app(ClassificationGate::class)->canAssign($user, $level)) {
                    $fail('You cannot classify a record above your own clearance.');
                } elseif ($sending && ! SendTasking::chiefsCanSee((new Tasking)->forceFill(['classification' => $level]), app(ClassificationGate::class))) {
                    $fail(SendTasking::CLASSIFICATION_PROBLEM);
                }
            }],
            'lgas' => [$sending ? 'required' : 'present', 'array', 'max:1000', $sending ? 'min:1' : 'min:0'],
            'lgas.*' => ['string', 'distinct', function (string $attribute, mixed $value, Closure $fail): void {
                if (! $this->choosable()->has((string) $value)) {
                    $fail('Taskings can be sent only to active LGAs of your own state.');
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'body.required' => 'Write the instruction before sending it.',
            'lgas.required' => 'Choose at least one LGA.',
            'lgas.min' => 'Choose at least one LGA.',
        ];
    }

    /**
     * The LGAs the sender may choose: active, in their own state, keyed by ULID.
     *
     * @return Collection<string, Lga>
     */
    public function choosable(): Collection
    {
        if ($this->lgas === null) {
            /** @var User $user */
            $user = $this->user();
            $this->lgas = app(JurisdictionOptions::class)->lgaQuery($user)->active()
                ->where('lgas.state_id', $user->state_id)
                ->get();
        }

        return $this->lgas->keyBy('ulid');
    }

    /**
     * The chosen LGAs' ids.
     *
     * @return list<int>
     */
    public function lgaIds(): array
    {
        $chosen = $this->validated('lgas');
        $choosable = $this->choosable();

        return array_map(
            fn (string $ulid): int => (int) $choosable->get($ulid)?->id,
            array_values(array_filter(is_array($chosen) ? $chosen : [], fn (mixed $ulid) => is_string($ulid) && $choosable->has($ulid))),
        );
    }

    /**
     * @return array{title: ?string, body: ?string, priority: TaskingPriority, response_due_at: ?CarbonImmutable, classification: Classification, lga_ids: list<int>}
     */
    public function values(): array
    {
        $title = trim((string) $this->validated('title'));
        $body = $this->validated('body');

        return [
            'title' => $title === '' ? null : $title,
            'body' => is_string($body) ? $body : null,
            'priority' => TaskingPriority::from((string) $this->validated('priority')),
            'response_due_at' => EvidenceUpdateRequest::time($this->validated('response_due_at')),
            'classification' => Classification::from((string) $this->validated('classification')),
            'lga_ids' => $this->lgaIds(),
        ];
    }
}
