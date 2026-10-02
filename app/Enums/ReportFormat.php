<?php

namespace App\Enums;

/** Report file formats (Phase 7D). */
enum ReportFormat: string
{
    case Pdf = 'pdf';
    case Xlsx = 'xlsx';
    case Csv = 'csv';

    public function mime(): string
    {
        return match ($this) {
            self::Pdf => 'application/pdf',
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv => 'text/csv',
        };
    }
}
