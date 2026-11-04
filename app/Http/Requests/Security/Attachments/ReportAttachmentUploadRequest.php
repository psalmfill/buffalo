<?php

namespace App\Http\Requests\Security\Attachments;

use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Models\CoverageReport;
use App\Models\CoverageReportAttachment;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Evidence\EvidenceFileInspector;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * One photo or video for a security report (Phase 11G), judged by its
 * content like evidence (EvidenceFileInspector, same size limits) but
 * limited to JPEG, PNG and WebP images and MP4 and WebM videos, and to
 * buffalo.security_reports.max_attachments per report. The classification
 * defaults to the report's, is never below it, and is within the
 * uploader's clearance.
 */
class ReportAttachmentUploadRequest extends FormRequest
{
    /** @var array{type: EvidenceType, mime: string, extension: string}|null */
    private ?array $detected = null;

    public function authorize(): bool
    {
        /** @var User $user */
        $user = $this->user();

        return $user->can('upload', [CoverageReportAttachment::class, $this->report()]);
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

                $max = (int) config('buffalo.security_reports.max_attachments');

                if (CoverageReportAttachment::query()->where('coverage_report_id', $this->report()->id)->count() >= $max) {
                    $fail("A security report can carry at most {$max} photos and videos.");

                    return;
                }

                $result = app(EvidenceFileInspector::class)->inspect((string) $value->getRealPath(), $value->getClientOriginalName());

                if (is_string($result)) {
                    $fail($result);

                    return;
                }

                if (! in_array($result['type'], [EvidenceType::Image, EvidenceType::Video], true)) {
                    $fail('Attach photos (JPEG, PNG, WebP) or videos (MP4, WebM) only.');

                    return;
                }

                $this->detected = $result;
            }],
            'caption' => ['nullable', 'string', 'max:200'],
            'classification' => ['nullable', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $problem = self::classificationProblem($this->user(), $this->report(), Classification::tryFrom((string) $value));

                if ($problem !== null) {
                    $fail($problem);
                }
            }],
        ];
    }

    /** Why a level cannot be given to an attachment of this report, if it cannot. */
    public static function classificationProblem(?User $user, CoverageReport $report, ?Classification $level): ?string
    {
        if ($level === null || $user === null) {
            return null;
        }

        if ($level->rank() < $report->classificationLevel()->rank()) {
            return "An attachment cannot be classified below its report ({$report->classificationLevel()->label()}).";
        }

        if (! app(ClassificationGate::class)->canAssign($user, $level)) {
            return 'You cannot classify a record above your own clearance.';
        }

        return null;
    }

    public function report(): CoverageReport
    {
        /** @var CoverageReport */
        return $this->route('report');
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
        return Classification::tryFrom((string) $this->validated('classification')) ?? $this->report()->classificationLevel();
    }
}
