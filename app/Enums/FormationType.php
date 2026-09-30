<?php

namespace App\Enums;

/**
 * Kind of agency formation (Phase 4A): a physical presence of a security
 * agency.
 */
enum FormationType: string
{
    case Headquarters = 'headquarters';
    case Command = 'command';
    case Division = 'division';
    case AreaCommand = 'area_command';
    case Station = 'station';
    case Post = 'post';
    case Base = 'base';
    case Barracks = 'barracks';
    case Checkpoint = 'checkpoint';
    case Outpost = 'outpost';
    case Office = 'office';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Headquarters => 'Headquarters',
            self::Command => 'Command',
            self::Division => 'Division',
            self::AreaCommand => 'Area command',
            self::Station => 'Station',
            self::Post => 'Post',
            self::Base => 'Base',
            self::Barracks => 'Barracks',
            self::Checkpoint => 'Checkpoint',
            self::Outpost => 'Outpost',
            self::Office => 'Office',
            self::Other => 'Other',
        };
    }
}
