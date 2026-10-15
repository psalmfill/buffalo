<?php

namespace App\Http\Requests\Security;

use App\Enums\Classification;
use App\Enums\CoveragePeriodType;
use App\Enums\CoverageRating;
use App\Enums\PatrolFrequency;
use App\Models\AgencyFormation;
use App\Models\Community;
use App\Models\CoverageReport;
use App\Models\CoverageReportDeployment;
use App\Models\Lga;
use App\Models\SecurityAgency;
use App\Models\User;
use App\Rules\RichTextRule;
use App\Support\Classification\ClassificationGate;
use App\Support\Jurisdiction\JurisdictionOptions;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Save a coverage report (Phase 4A), and optionally submit it (`submit`).
 * The LGA and period are chosen once. Deployments are the full list:
 * rows with a `ulid` update existing deployments, rows without one are new,
 * and deployments left out are removed.
 *
 * On submit: a rating and a summary are required, the gaps when the rating
 * is not `adequate`, and at least one deployment unless the rating is
 * `critical`. Authorisation is in the controller.
 */
class CoverageReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function report(): ?CoverageReport
    {
        $report = $this->route('report');

        return $report instanceof CoverageReport ? $report : null;
    }

    public function lga(): ?Lga
    {
        $current = $this->report();

        if ($current !== null) {
            return $current->lga;
        }

        $ulid = $this->input('lga');

        if (! is_string($ulid)) {
            return null;
        }

        /** @var User $user */
        $user = $this->user();

        return app(JurisdictionOptions::class)->lgaQuery($user)->active()->where('ulid', $ulid)->first();
    }

    public function submitting(): bool
    {
        return $this->boolean('submit');
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editing = $this->report() !== null;
        $submitting = $this->submitting();
        $rating = CoverageRating::tryFrom((string) $this->input('overall_rating'));
        $gapsRequired = $submitting && $rating !== null && $rating !== CoverageRating::Adequate;

        return [
            'submit' => ['sometimes', 'boolean'],
            'lga' => $editing ? ['prohibited'] : ['required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->lga() === null) {
                    $fail('Choose an LGA within your jurisdiction.');
                }
            }],
            'period_type' => $editing ? ['prohibited'] : ['required', Rule::enum(CoveragePeriodType::class)],
            'period_start' => $editing ? ['prohibited'] : ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'period_end' => $editing ? ['prohibited'] : ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'overall_rating' => [$submitting ? 'required' : 'nullable', Rule::enum(CoverageRating::class)],
            'summary' => [$submitting ? 'required' : 'nullable', new RichTextRule(20000, required: $submitting)],
            'gaps_identified' => [$gapsRequired ? 'required' : 'nullable', new RichTextRule(20000, required: $gapsRequired)],
            'recommendations' => ['nullable', new RichTextRule(20000)],
            'classification' => ['required', Rule::enum(Classification::class), function (string $attribute, mixed $value, Closure $fail): void {
                $level = Classification::tryFrom((string) $value);
                /** @var User $user */
                $user = $this->user();

                if ($level !== null && ! app(ClassificationGate::class)->canAssign($user, $level)) {
                    $fail('You cannot classify a record above your own clearance.');
                }
            }],
            'deployments' => ['present', 'array', 'max:100', ...($submitting && $rating !== CoverageRating::Critical ? ['min:1'] : [])],
            'deployments.*.ulid' => ['nullable', 'string', 'distinct'],
            'deployments.*.agency' => ['required', 'string'],
            'deployments.*.formation' => ['nullable', 'string'],
            'deployments.*.personnel_count' => ['required', 'integer', 'min:0', 'max:1000000'],
            'deployments.*.vehicles_count' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'deployments.*.patrol_frequency' => ['required', Rule::enum(PatrolFrequency::class)],
            'deployments.*.communities' => ['present', 'array', 'max:1000'],
            // Several deployments may cover the same community; duplicates
            // within one row are ignored.
            'deployments.*.communities.*' => ['string'],
            'deployments.*.areas_covered' => ['nullable', 'string', 'max:5000'],
            'deployments.*.remarks' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'lga.prohibited' => 'A report\'s LGA cannot be changed.',
            'period_type.prohibited' => 'A report\'s period cannot be changed.',
            'period_start.prohibited' => 'A report\'s period cannot be changed.',
            'period_end.prohibited' => 'A report\'s period cannot be changed.',
            'period_start.before_or_equal' => 'The period cannot start in the future.',
            'deployments.min' => 'Add at least one deployment, or rate the coverage as critical.',
            'gaps_identified.required' => 'Describe the gaps when the coverage is not adequate.',
            'summary.required' => 'A summary is required to submit the report.',
            'overall_rating.required' => 'Choose an overall rating to submit the report.',
        ];
    }

    /**
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $this->checkPeriod($validator);
            $this->checkDeployments($validator);
        }];
    }

    /**
     * Validated values for SaveCoverageReport.
     *
     * @return array{period_type: CoveragePeriodType|null, period_start: string|null, period_end: string|null, overall_rating: string|null, summary: string|null, gaps_identified: string|null, recommendations: string|null, classification: string, deployments: list<array{id: int|null, security_agency_id: int, agency_formation_id: int|null, personnel_count: int, vehicles_count: int|null, patrol_frequency: string, areas_covered: string|null, remarks: string|null, community_ids: list<int>}>}
     */
    public function values(): array
    {
        $data = $this->validated();
        /** @var Lga $lga */
        $lga = $this->lga();
        $report = $this->report();

        $deployments = [];

        foreach ($data['deployments'] as $row) {
            $communityUlids = array_values(array_unique(array_filter((array) $row['communities'], 'is_string')));

            $existing = isset($row['ulid']) && $report !== null
                ? DB::table('coverage_report_deployments')->where('coverage_report_id', $report->id)->where('ulid', $row['ulid'])->first(['id', 'agency_formation_id', 'security_agency_id'])
                : null;
            $agencyId = (int) SecurityAgency::query()->where('ulid', $row['agency'])->value('id');

            $deployments[] = [
                'id' => $existing === null ? null : (int) $existing->id,
                'security_agency_id' => $agencyId,
                // Absent: unchanged (a formation the user cannot see), as long
                // as the agency is unchanged.
                'agency_formation_id' => array_key_exists('formation', $row)
                    ? (isset($row['formation']) ? (int) AgencyFormation::query()->where('ulid', $row['formation'])->value('id') : null)
                    : ($existing !== null && (int) $existing->security_agency_id === $agencyId && $existing->agency_formation_id !== null ? (int) $existing->agency_formation_id : null),
                'personnel_count' => (int) $row['personnel_count'],
                'vehicles_count' => isset($row['vehicles_count']) ? (int) $row['vehicles_count'] : null,
                'patrol_frequency' => (string) $row['patrol_frequency'],
                'areas_covered' => self::text($row['areas_covered'] ?? null),
                'remarks' => self::text($row['remarks'] ?? null),
                'community_ids' => $communityUlids === [] ? [] : array_values(array_map('intval', Community::query()
                    ->where('lga_id', $lga->id)->whereIn('ulid', $communityUlids)->pluck('id')->all())),
            ];
        }

        return [
            'period_type' => $report === null ? CoveragePeriodType::from((string) $data['period_type']) : null,
            'period_start' => $report === null ? (string) $data['period_start'] : null,
            'period_end' => $report === null ? (string) $data['period_end'] : null,
            'overall_rating' => isset($data['overall_rating']) ? (string) $data['overall_rating'] : null,
            'summary' => self::text($data['summary'] ?? null),
            'gaps_identified' => self::text($data['gaps_identified'] ?? null),
            'recommendations' => self::text($data['recommendations'] ?? null),
            'classification' => (string) $data['classification'],
            'deployments' => $deployments,
        ];
    }

    private function checkPeriod(Validator $validator): void
    {
        if ($this->report() !== null) {
            return;
        }

        $type = CoveragePeriodType::from((string) $this->input('period_type'));
        $start = CarbonImmutable::parse((string) $this->input('period_start'));
        $end = CarbonImmutable::parse((string) $this->input('period_end'));

        if (! $type->matchesBoundaries($start, $end)) {
            $validator->errors()->add('period_start', match ($type) {
                CoveragePeriodType::Weekly => 'A weekly report runs from a Monday to the following Sunday.',
                CoveragePeriodType::Monthly => 'A monthly report covers one calendar month.',
                CoveragePeriodType::Quarterly => 'A quarterly report covers one calendar quarter.',
                CoveragePeriodType::AdHoc => 'The period is not valid.',
            });

            return;
        }

        $lga = $this->lga();

        // One report per LGA and period (all reports, whoever can see them:
        // ids only).
        if ($lga !== null && $type !== CoveragePeriodType::AdHoc && DB::table('coverage_reports')
            ->where('lga_id', $lga->id)->where('period_type', $type->value)
            ->where('period_start', $start->toDateString())->whereNull('deleted_at')->exists()) {
            $validator->errors()->add('period_start', "{$lga->name} LGA already has a {$type->label()} report for {$type->describe($start, $end)}.");
        }
    }

    private function checkDeployments(Validator $validator): void
    {
        $lga = $this->lga();
        $report = $this->report();

        if ($lga === null) {
            return;
        }

        foreach ((array) $this->input('deployments') as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $current = null;

            if (isset($row['ulid'])) {
                $current = $report === null ? null : CoverageReportDeployment::query()->with('agency')
                    ->where('coverage_report_id', $report->id)->where('ulid', $row['ulid'])->first();

                if ($current === null) {
                    $validator->errors()->add("deployments.{$index}.ulid", 'This deployment is not part of the report.');

                    continue;
                }
            }

            $agency = SecurityAgency::query()->where('ulid', $row['agency'])->first();

            if ($agency === null || (! $agency->is_active && $current?->agency->ulid !== $agency->ulid)) {
                $validator->errors()->add("deployments.{$index}.agency", 'Choose an active agency.');

                continue;
            }

            if (isset($row['formation'])) {
                $formation = AgencyFormation::query()->where('ulid', $row['formation'])->first();

                if ($formation === null || $formation->security_agency_id !== $agency->id || $formation->state_id !== $lga->state_id) {
                    $validator->errors()->add("deployments.{$index}.formation", "The formation must be a formation of {$agency->name} in {$lga->state->name} State.");
                }
            }

            $communities = array_values(array_unique(array_filter((array) ($row['communities'] ?? []), 'is_string')));

            if ($communities !== [] && Community::query()->where('lga_id', $lga->id)->whereIn('ulid', $communities)->count() !== count($communities)) {
                $validator->errors()->add("deployments.{$index}.communities", "Covered communities must be communities of {$lga->name} LGA.");
            }
        }
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
