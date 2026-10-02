<?php

namespace App\Services\Reports;

use App\Enums\Classification;
use App\Models\ReportRun;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Markings on every report (Phase 7D, CLAUDE.md decisions log): the
 * classification banner (the run's level), "Generated for [name], [role],
 * [jurisdiction] on [date time]" and the report reference RPT-<ULID>.
 */
final class ReportMarkings
{
    public function __construct(
        public readonly string $classification,
        public readonly string $generatedFor,
        public readonly string $reference,
        public readonly string $watermark,
    ) {}

    public static function for(ReportRun $run, User $user, Classification $level, ?CarbonImmutable $at = null): self
    {
        $at ??= CarbonImmutable::now((string) config('app.timezone'));

        return new self(
            mb_strtoupper($level->label()),
            'Generated for '.$user->name.', '.($user->getRole()?->label() ?? 'No role').', '.$user->jurisdictionLabel().' on '.$at->format('j M Y H:i T'),
            $run->reference(),
            $user->name,
        );
    }

    /**
     * The first rows of XLSX and CSV files.
     *
     * @return list<string>
     */
    public function rows(string $title): array
    {
        return [$this->classification, $this->generatedFor, $this->reference.' — '.$title];
    }
}
