<?php

namespace App\Support\Jurisdiction\Contracts;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Optional for jurisdiction-scoped models whose records are not all tied
 * to one LGA: an explicit rule for what an LGA-level user (LGA security
 * chief) sees, applied identically by JurisdictionScope (SQL) and
 * JurisdictionGate (one record).
 *
 * Example: political offices without an LGA (senators) are visible to an
 * LGA chief when the office's senatorial district contains their LGA.
 *
 * The rule may only narrow what the chief would otherwise see within their
 * state; it never replaces the state check for state-level users.
 */
interface LgaVisibilityRule
{
    /**
     * Restricts $query (in its own nested where group) to the records an
     * LGA-level user of $lgaId may see.
     *
     * @param  Builder<covariant Model>  $query
     */
    public function applyLgaVisibility(Builder $query, int $lgaId): void;

    /** Whether this record is visible to an LGA-level user of $lgaId. */
    public function visibleToLga(int $lgaId): bool;
}
