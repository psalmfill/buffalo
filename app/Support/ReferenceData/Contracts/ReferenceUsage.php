<?php

namespace App\Support\ReferenceData\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Whether any record uses a reference value (e.g. an incident of this type).
 * Once a value is in use, an incident type's detail fields may only evolve
 * compatibly (SchemaValidator::assertCompatible).
 */
interface ReferenceUsage
{
    public function isInUse(Model $value): bool;
}
