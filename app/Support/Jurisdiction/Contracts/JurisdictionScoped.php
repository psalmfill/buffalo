<?php

namespace App\Support\Jurisdiction\Contracts;

/**
 * Implemented (via App\Models\Concerns\HasJurisdiction) by every model whose
 * records belong to a place.
 */
interface JurisdictionScoped
{
    /** Column holding the record's state id. */
    public function jurisdictionStateColumn(): string;

    /** Column holding the record's LGA id, or null for state-level records. */
    public function jurisdictionLgaColumn(): ?string;

    /** Whether super admins may see these records (default: no). */
    public function isVisibleToSuperAdmin(): bool;
}
