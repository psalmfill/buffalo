<?php

namespace App\Support\Persons\Merge;

use App\Enums\DuplicateStrength;
use App\Models\Person;
use App\Models\User;
use App\Support\Search\Trigram;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Duplicate person candidates (Phase 10A.3).
 *
 * Runs entirely on a scoped person query: for a user, their OWN
 * jurisdiction (`ownJurisdiction`: grants and disseminations never
 * contribute) within their classification; for the nightly count, one
 * state in the system context. Merged and soft-deleted persons, dismissed
 * pairs and pairs in different states are excluded.
 *
 * | Signal                                        | Strength |
 * |-----------------------------------------------|----------|
 * | Same NIN (blind index)                        | certain  |
 * | Same phone (blind index)                      | strong   |
 * | Same DOB (blind index) + name/alias sim ≥ 0.75 | strong   |
 * | Name/alias similarity ≥ 0.85, same LGA        | possible |
 *
 * Blind index pairs use the (name, value) index on blind_indexes. Name
 * pairs use prefix filtering (see the SQL) and the `%` operator with the
 * threshold set for the transaction: a self-join probing the trigram GIN
 * index once per name is quadratic when names share common trigrams (it
 * exceeded 300 s on 20,000 persons in one LGA), while prefix filtering is
 * exact and took 1.5 s. The DOB pairs' name score is computed with
 * similarity() only on the few pairs the blind index already found.
 */
final class PersonDuplicateFinder
{
    /**
     * Candidate pairs among the user's own records, strongest first.
     *
     * @param  (\Closure(Builder<Person>): mixed)|null  $narrow  extra conditions (state or LGA filter)
     * @return list<array{a: Person, b: Person, strength: DuplicateStrength, signals: list<string>, name_score: float}>
     */
    public function forUser(User $user, ?\Closure $narrow = null, ?int $limit = null): array
    {
        $scoped = Person::query()->ownJurisdiction($user);

        if ($narrow !== null) {
            $narrow($scoped);
        }

        $pairs = $this->pairs($scoped, $limit ?? (int) config('buffalo.merges.candidates_limit', 200));
        $ids = array_unique([...array_column($pairs, 'lo'), ...array_column($pairs, 'hi')]);

        // Loaded through the same scopes again (defence in depth).
        $persons = Person::query()->ownJurisdiction($user)
            ->with(['lga', 'state', 'roles', 'media' => fn ($q) => $q->where('collection_name', Person::PHOTOS)])
            ->whereIn('persons.id', $ids)
            ->get()
            ->keyBy('id');

        $results = [];

        foreach ($pairs as $pair) {
            $a = $persons->get($pair['lo']);
            $b = $persons->get($pair['hi']);

            if ($a instanceof Person && $b instanceof Person) {
                $results[] = ['a' => $a, 'b' => $b, 'strength' => $pair['strength'], 'signals' => $pair['signals'], 'name_score' => $pair['name_score']];
            }
        }

        return $results;
    }

    /**
     * Counts per strength among $scoped (the nightly job: one state, in the
     * system context).
     *
     * @param  Builder<Person>  $scoped
     * @return array{certain: int, strong: int, possible: int, total: int}
     */
    public function counts(Builder $scoped): array
    {
        $counts = ['certain' => 0, 'strong' => 0, 'possible' => 0, 'total' => 0];

        foreach ($this->pairs($scoped, null) as $pair) {
            $counts[$pair['strength']->value]++;
            $counts['total']++;
        }

        return $counts;
    }

    /**
     * Whether this pair is currently a candidate (for the review page).
     *
     * @return array{strength: DuplicateStrength, signals: list<string>, name_score: float}|null
     */
    public function pairSignals(Person $a, Person $b): ?array
    {
        $scoped = Person::query()->whereIn('persons.id', [$a->id, $b->id]);

        foreach ($this->pairs($scoped, null) as $pair) {
            return ['strength' => $pair['strength'], 'signals' => $pair['signals'], 'name_score' => $pair['name_score']];
        }

        return null;
    }

    /**
     * @param  Builder<Person>  $scoped
     * @return list<array{lo: int, hi: int, strength: DuplicateStrength, signals: list<string>, name_score: float}>
     */
    private function pairs(Builder $scoped, ?int $limit): array
    {
        $base = $scoped->clone()->select(['persons.id', 'persons.state_id', 'persons.lga_id'])->toBase();
        $type = (new Person)->getMorphClass();
        $dobThreshold = (float) config('buffalo.merges.dob_name_similarity', 0.75);
        $nameThreshold = (float) config('buffalo.merges.name_similarity', 0.85);

        $blindPairs = fn (string $index, string $signal): string => <<<SQL
            SELECT LEAST(a.id, b.id) AS lo, GREATEST(a.id, b.id) AS hi, '{$signal}' AS signal
              FROM blind_indexes x
              JOIN scoped a ON a.id = x.indexable_id
              JOIN blind_indexes y ON y.name = x.name AND y.value = x.value AND y.indexable_type = x.indexable_type AND y.indexable_id <> x.indexable_id
              JOIN scoped b ON b.id = y.indexable_id AND b.state_id = a.state_id
             WHERE x.indexable_type = ? AND x.name = '{$index}' AND a.id < b.id
            SQL;

        // Literal SQL only; every value is bound.
        $sql = <<<SQL
            WITH scoped AS MATERIALIZED ({$base->toSql()}),
            variants AS MATERIALIZED (
                SELECT s.id, s.state_id, s.lga_id, p.search_name AS t FROM scoped s JOIN persons p ON p.id = s.id
                UNION ALL
                SELECT s.id, s.state_id, s.lga_id, al.search_alias FROM scoped s JOIN person_aliases al ON al.person_id = s.id AND al.deleted_at IS NULL
            ),
            -- Name pairs by prefix filtering: pg_trgm similarity is the
            -- Jaccard index of two trigram sets, so two names with
            -- similarity >= t share at least one of the n - ceil(t * n) + 1
            -- rarest trigrams of each (n = trigrams in the name; rarity
            -- counted within the scope, ties by the trigram itself). Joining
            -- on those prefix trigrams finds every qualifying pair without
            -- probing common trigrams; `%` then checks the threshold.
            trigrams AS MATERIALIZED (
                SELECT v.id, v.lga_id, v.t, g FROM variants v, unnest(show_trgm(v.t)) AS g
            ),
            frequency AS (SELECT g, count(*) AS c FROM trigrams GROUP BY g),
            ranked AS (
                SELECT tr.id, tr.lga_id, tr.t, tr.g,
                       row_number() OVER (PARTITION BY tr.id, tr.t ORDER BY f.c, tr.g) AS rn,
                       count(*) OVER (PARTITION BY tr.id, tr.t) AS n
                  FROM trigrams tr JOIN frequency f ON f.g = tr.g
            ),
            prefix AS MATERIALIZED (
                SELECT id, lga_id, t, g FROM ranked WHERE rn <= n - ceil(?::numeric * n - 0.000001) + 1
            ),
            pairs AS (
                {$blindPairs('nin_bidx', 'nin')}
                UNION ALL
                {$blindPairs('phone_bidx', 'phone')}
                UNION ALL
                {$blindPairs('dob_bidx', 'dob')}
                UNION ALL
                SELECT DISTINCT LEAST(p1.id, p2.id), GREATEST(p1.id, p2.id), 'name'
                  FROM prefix p1
                  JOIN prefix p2 ON p2.g = p1.g AND p2.lga_id = p1.lga_id AND p2.id <> p1.id
                 WHERE p1.t % p2.t
            ),
            grouped AS (
                SELECT lo, hi, array_agg(DISTINCT signal ORDER BY signal) AS signals
                  FROM pairs
                 WHERE NOT EXISTS (SELECT 1 FROM person_duplicate_dismissals d WHERE d.person_a_id = pairs.lo AND d.person_b_id = pairs.hi)
                 GROUP BY lo, hi
            )
            SELECT g.lo, g.hi, array_to_json(g.signals) AS signals,
                   (SELECT COALESCE(MAX(similarity(n1.t, n2.t)), 0)
                      FROM (SELECT search_name AS t FROM persons WHERE id = g.lo
                            UNION ALL SELECT search_alias FROM person_aliases WHERE person_id = g.lo AND deleted_at IS NULL) n1
                     CROSS JOIN (SELECT search_name AS t FROM persons WHERE id = g.hi
                            UNION ALL SELECT search_alias FROM person_aliases WHERE person_id = g.hi AND deleted_at IS NULL) n2) AS name_score
              FROM grouped g
            SQL;

        // Positional bindings: the scoped query's, the name threshold (prefix
        // length), then the morph type once per blind index.
        $bindings = [...$base->getBindings(), $nameThreshold, ...array_fill(0, 3, $type)];

        $rows = Trigram::withThresholds($nameThreshold, 1.0, function () use ($sql, $bindings): array {
            // Every join here is set-based. Row estimates for the CTEs are
            // often far off (fresh data, stale statistics), and a nested
            // loop re-scanning a materialised CTE per row is quadratic:
            // hash and merge joins only, for this transaction.
            DB::statement('SET LOCAL enable_nestloop = off');

            return DB::select($sql, $bindings);
        });

        $pairs = [];

        foreach ($rows as $row) {
            /** @var list<string> $signals */
            $signals = json_decode((string) $row->signals, true);
            $score = round((float) $row->name_score, 3);

            if (in_array('dob', $signals, true) && $score < $dobThreshold) {
                $signals = array_values(array_diff($signals, ['dob']));
            }

            $strength = match (true) {
                in_array('nin', $signals, true) => DuplicateStrength::Certain,
                in_array('phone', $signals, true), in_array('dob', $signals, true) => DuplicateStrength::Strong,
                in_array('name', $signals, true) => DuplicateStrength::Possible,
                default => null,
            };

            if ($strength === null) {
                continue;
            }

            $pairs[] = ['lo' => (int) $row->lo, 'hi' => (int) $row->hi, 'strength' => $strength, 'signals' => $signals, 'name_score' => $score];
        }

        usort($pairs, fn (array $x, array $y): int => [$y['strength']->rank(), $y['name_score'], $x['lo']] <=> [$x['strength']->rank(), $x['name_score'], $y['lo']]);

        return $limit === null ? $pairs : array_slice($pairs, 0, $limit);
    }

    /** Labels for the signals, for the candidates view. */
    public static function signalLabel(string $signal): string
    {
        return match ($signal) {
            'nin' => 'Same NIN',
            'phone' => 'Same phone number',
            'dob' => 'Same date of birth and similar name',
            'name' => 'Similar name in the same LGA',
            default => $signal,
        };
    }
}
