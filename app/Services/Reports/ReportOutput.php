<?php

namespace App\Services\Reports;

use App\Enums\Classification;

/** A generated report file (Phase 7D), before it is stored. */
final readonly class ReportOutput
{
    public function __construct(
        public string $path,
        public Classification $classification,
        public ?int $rows = null,
        public ?int $pages = null,
    ) {}
}
