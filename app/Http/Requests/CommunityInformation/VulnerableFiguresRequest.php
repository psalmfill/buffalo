<?php

namespace App\Http\Requests\CommunityInformation;

use App\Enums\VulnerableCategory;
use App\Models\Community;
use App\Models\VulnerablePopulationStat;
use Closure;
use Illuminate\Validation\Rule;

/**
 * Vulnerable population figures: aggregate counts only. There is no
 * free-text field; `source` names an office, not a person.
 */
class VulnerableFiguresRequest extends LgaRecordRequest
{
    protected function recordParameter(): string
    {
        return 'figure';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $count = ['required', 'integer', 'min:0', 'max:10000000'];

        return [
            'community' => ['nullable', 'string', $this->communityOfLga()],
            'category' => ['required', Rule::enum(VulnerableCategory::class)],
            'adult_male' => $count,
            'adult_female' => $count,
            'child_male' => $count,
            'child_female' => $count,
            'as_of_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today', $this->oneFigurePerDate()],
            'source' => ['required', 'string', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['as_of_date.before_or_equal' => 'The date cannot be in the future.'];
    }

    /**
     * @return array{community: Community|null, category: string, adult_male: int, adult_female: int, child_male: int, child_female: int, as_of_date: string, source: string}
     */
    public function values(): array
    {
        $data = $this->validated();

        return [
            'community' => $this->community(),
            'category' => (string) $data['category'],
            'adult_male' => (int) $data['adult_male'],
            'adult_female' => (int) $data['adult_female'],
            'child_male' => (int) $data['child_male'],
            'child_female' => (int) $data['child_female'],
            'as_of_date' => (string) $data['as_of_date'],
            'source' => (string) $data['source'],
        ];
    }

    /**
     * One figure per LGA/community, category and date (the database also
     * enforces it).
     */
    private function oneFigurePerDate(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $record = $this->record();

            $exists = VulnerablePopulationStat::query()
                ->where('lga_id', $this->lga()->id)
                ->where('community_id', $this->community()?->id)
                ->where('category', $this->input('category'))
                ->whereDate('as_of_date', (string) $value)
                ->when($record !== null, fn ($query) => $query->whereKeyNot($record?->getKey()))
                ->exists();

            if ($exists) {
                $fail('Figures for this category and date are already recorded. Edit them instead, or choose another date.');
            }
        };
    }
}
