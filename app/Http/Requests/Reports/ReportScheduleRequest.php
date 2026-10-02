<?php

namespace App\Http\Requests\Reports;

use App\Enums\ReportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A report schedule (Phase 7D): SITREP, weekly or monthly summary; daily,
 * weekly (weekday 1 = Monday … 7 = Sunday) or monthly (day 1–28) at HH:MM
 * Africa/Lagos. The place is validated by ReportParameters.
 */
class ReportScheduleRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in([ReportType::Sitrep->value, ReportType::Weekly->value, ReportType::Monthly->value])],
            'cadence' => ['required', Rule::in(['daily', 'weekly', 'monthly'])],
            'time_of_day' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'weekday' => ['nullable', 'required_if:cadence,weekly', 'integer', 'between:1,7'],
            'day_of_month' => ['nullable', 'required_if:cadence,monthly', 'integer', 'between:1,28'],
            'parameters' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['time_of_day.regex' => 'Enter a time as HH:MM (24-hour).'];
    }

    public function reportType(): ReportType
    {
        return ReportType::from((string) $this->validated('type'));
    }
}
