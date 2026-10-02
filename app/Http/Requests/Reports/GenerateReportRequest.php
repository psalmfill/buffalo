<?php

namespace App\Http\Requests\Reports;

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A report request (Phase 7D). The parameters are validated per type by
 * ReportParameters in the requester's context; authorisation is in the
 * controller (ReportPolicy::generate).
 */
class GenerateReportRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ReportType::class)],
            'format' => ['required', Rule::enum(ReportFormat::class)],
            'parameters' => ['nullable', 'array'],
        ];
    }

    public function reportType(): ReportType
    {
        return ReportType::from((string) $this->validated('type'));
    }

    public function reportFormat(): ReportFormat
    {
        return ReportFormat::from((string) $this->validated('format'));
    }

    /**
     * @return array<string, mixed>
     */
    public function reportParameters(): array
    {
        /** @var array<string, mixed> */
        return (array) ($this->validated('parameters') ?? []);
    }
}
