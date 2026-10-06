<?php

namespace App\Enums;

/**
 * What a user did with a sensitive record (record_access_logs).
 */
enum AccessAction: string
{
    case View = 'view';
    case Export = 'export';
    case Print = 'print';
    case Download = 'download';

    public function label(): string
    {
        return match ($this) {
            self::View => 'Viewed',
            self::Export => 'Exported',
            self::Print => 'Printed',
            self::Download => 'Downloaded',
        };
    }
}
