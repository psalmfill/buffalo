<?php

namespace Database\Factories;

use App\Enums\EvidenceScanStatus;
use App\Enums\EvidenceType;
use App\Models\CoverageReport;
use App\Models\CoverageReportAttachment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * A row without a stored file: tests that need the file upload through
 * StoreReportAttachment.
 *
 * @extends Factory<CoverageReportAttachment>
 */
class CoverageReportAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'coverage_report_id' => CoverageReport::factory(),
            'state_id' => fn (array $a) => DB::table('coverage_reports')->where('id', $a['coverage_report_id'])->value('state_id'),
            'lga_id' => fn (array $a) => DB::table('coverage_reports')->where('id', $a['coverage_report_id'])->value('lga_id'),
            'classification' => fn (array $a) => DB::table('coverage_reports')->where('id', $a['coverage_report_id'])->value('classification'),
            'caption' => fake()->sentence(4),
            'media_type' => EvidenceType::Image,
            'original_filename' => 'photo.jpg',
            'mime_type' => 'image/jpeg',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', fake()->uuid()),
            'scan_status' => EvidenceScanStatus::Clean,
            'uploaded_by' => User::factory(),
        ];
    }

    public function on(CoverageReport $report): static
    {
        return $this->state([
            'coverage_report_id' => $report->id,
            'state_id' => $report->state_id,
            'lga_id' => $report->lga_id,
            'classification' => $report->classification,
        ]);
    }
}
