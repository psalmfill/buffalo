<?php

namespace App\Services\Analytics;

use App\Models\User;
use App\Support\Classification\ClassificationGate;
use Carbon\CarbonImmutable;

/**
 * Markings drawn into every analytics export (Phase 7C, CLAUDE.md decisions
 * log): the classification (the highest level the figures may include,
 * i.e. the viewer's clearance) and "Generated for [name] at [time]".
 */
final class ExportMarking
{
    public static function classification(User $user): string
    {
        $level = app(ClassificationGate::class)->maxLevelFor($user);

        return mb_strtoupper(($level?->label() ?? 'Restricted')).' — aggregate figures';
    }

    public static function generated(User $user, ?CarbonImmutable $at = null): string
    {
        $at ??= CarbonImmutable::now((string) config('app.timezone'));

        return 'Generated for '.$user->name.' at '.$at->format('j M Y H:i T');
    }
}
