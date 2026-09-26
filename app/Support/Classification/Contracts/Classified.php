<?php

namespace App\Support\Classification\Contracts;

use App\Enums\Classification;

/**
 * A model with a `classification` column (HasClassification).
 */
interface Classified
{
    public function classificationLevel(): Classification;
}
