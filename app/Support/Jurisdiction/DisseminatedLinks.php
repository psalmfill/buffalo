<?php

namespace App\Support\Jurisdiction;

use App\Support\Jurisdiction\Contracts\Disseminable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Link rows of a disseminated record (Phase 7A).
 *
 * SANCTIONED SCOPE BYPASS (inside App\Support\Jurisdiction). Link rows
 * (threat report ↔ flashpoint, group, incident; flashpoint ↔ incident,
 * security note) sit on the owning record's place, so a recipient who sees
 * the record only through a dissemination would see none of them. For that
 * viewer, and only for links of that record, the jurisdiction scope is
 * removed from the LINK query. The link models' own "both sides visible"
 * scope still applies, so a linked record appears only when the viewer
 * could already see it through their own scopes: nothing hidden is listed
 * or counted.
 */
final class DisseminatedLinks
{
    /**
     * @template TLink of Model
     *
     * @param  Builder<TLink>  $links  already filtered to links of $subject
     * @return Builder<TLink>
     */
    public static function of(Builder $links, Model&Disseminable $subject): Builder
    {
        $viewer = app(JurisdictionContext::class)->currentUser();

        if ($viewer !== null && app(JurisdictionGate::class)->viaGrantOnly($viewer, $subject)) {
            return $links->withoutGlobalScope(JurisdictionScope::class);
        }

        return $links;
    }
}
