<?php

namespace App\Support\ReferenceData;

use App\Support\ReferenceData\Contracts\ReferenceUsage;
use Illuminate\Database\Eloquent\Model;

/**
 * No module references lookup values yet (Phase 2A). The phases that add
 * persons (3), incidents (5) and threats (6) replace this binding with a
 * real check.
 */
final class NoReferenceUsage implements ReferenceUsage
{
    public function isInUse(Model $value): bool
    {
        return false;
    }
}
