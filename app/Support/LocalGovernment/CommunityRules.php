<?php

namespace App\Support\LocalGovernment;

use App\Models\Community;
use App\Models\Lga;
use App\Models\Ward;
use Illuminate\Support\Facades\DB;

/**
 * Checks shared by the community form and the CSV import.
 */
final class CommunityRules
{
    /**
     * An existing (non-deleted) community of the LGA with this name,
     * case-insensitively.
     */
    public function sameName(Lga $lga, string $name, ?int $exceptId = null): ?Community
    {
        return Community::query()
            ->where('lga_id', $lga->id)
            ->whereRaw('lower(name) = lower(?)', [trim($name)])
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId))
            ->first();
    }

    /**
     * The most similar existing community of the LGA (by name or other
     * names), if its trigram similarity is at least $threshold.
     *
     * @return array{community: Community, similarity: float}|null
     */
    public function similar(Lga $lga, string $name, float $threshold = 0.6): ?array
    {
        $community = Community::query()
            ->where('lga_id', $lga->id)
            ->select('communities.*')
            ->selectRaw(<<<'SQL'
                greatest(
                    similarity(lower(buffalo_unaccent(communities.name)), lower(buffalo_unaccent(?))),
                    coalesce((
                        SELECT max(similarity(lower(buffalo_unaccent(other.value)), lower(buffalo_unaccent(?))))
                        FROM jsonb_array_elements_text(communities.other_names) AS other(value)
                    ), 0)
                ) AS name_similarity
            SQL, [$name, $name])
            ->orderByDesc('name_similarity')
            ->first();

        $similarity = (float) ($community?->getAttribute('name_similarity') ?? 0);

        return $community !== null && $similarity >= $threshold
            ? ['community' => $community, 'similarity' => round($similarity, 2)]
            : null;
    }

    public function ward(Lga $lga, string $field, string $value): ?Ward
    {
        return Ward::query()->where('lga_id', $lga->id)->where($field, $value)->first();
    }

    /**
     * Why these coordinates cannot be a location in the LGA, or null if
     * they can. The LGA boundary is checked only when the LGA has one.
     */
    public function locationError(Lga $lga, float $latitude, float $longitude): ?string
    {
        if (! Nigeria::contains($latitude, $longitude)) {
            return 'The location must be within Nigeria.';
        }

        $inside = DB::selectOne(
            'SELECT boundary IS NULL OR ST_Covers(boundary, ST_SetSRID(ST_MakePoint(?, ?), 4326)::geography) AS inside FROM lgas WHERE id = ?',
            [$longitude, $latitude, $lga->id],
        );

        return $inside !== null && $inside->inside
            ? null
            : "The location is outside the boundary of {$lga->name} LGA.";
    }

    /**
     * The (non-deleted) community of the LGA with this ULID, if any.
     */
    public function communityOf(Lga $lga, mixed $ulid): ?Community
    {
        return is_string($ulid)
            ? Community::query()->where('lga_id', $lga->id)->where('ulid', $ulid)->first()
            : null;
    }
}
