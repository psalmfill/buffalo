<?php

namespace App\Support\Persons;

use App\Models\Person;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Possible duplicates of a person about to be created, among the persons
 * the user can see (both scopes apply; cross-jurisdiction checks are 3C):
 *
 * - name or an alias with trigram similarity >= 0.6 to the new name, or
 * - the same NIN, phone number or date of birth (blind indexes).
 */
final class SimilarPersons
{
    public const LIMIT = 10;

    /**
     * @param  array{first_name: string, last_name: string, other_names?: string|null, nin?: string|null, phone?: string|null, date_of_birth?: string|null}  $candidate
     * @return list<array{person: Person, reasons: list<string>}>
     */
    public function find(array $candidate): array
    {
        $name = trim(implode(' ', array_filter([$candidate['first_name'], $candidate['other_names'] ?? null, $candidate['last_name']])));
        $nin = self::filled($candidate['nin'] ?? null);
        $phone = self::filled($candidate['phone'] ?? null);
        $dob = self::filled($candidate['date_of_birth'] ?? null);

        /** @var Collection<int, Person> $matches */
        $matches = PersonSearch::withThresholds(PersonSearch::DUPLICATE_SIMILARITY, 1.0, fn () => PersonSearch::selectNameScore(
            Person::query()->with(['lga', 'roles'])->where(function (Builder $query) use ($name, $nin, $phone, $dob): void {
                $query->where(fn (Builder $q) => PersonSearch::whereNameMatches($q, $name, partial: false));

                if ($nin !== null) {
                    $query->orWhereBlind('nin', 'nin_bidx', $nin);
                }

                if ($phone !== null) {
                    $query->orWhereBlind('phone', 'phone_bidx', $phone);
                }

                if ($dob !== null) {
                    $query->orWhereBlind('date_of_birth', 'dob_bidx', $dob);
                }
            }),
            $name,
            partial: false,
        )->get());

        $results = [];

        foreach ($matches as $person) {
            $reasons = [];

            if ($nin !== null && $person->nin === $nin) {
                $reasons[] = 'Same NIN';
            }

            if ($phone !== null && $person->phone === $phone) {
                $reasons[] = 'Same phone number';
            }

            if ((float) $person->getAttribute('name_score') >= PersonSearch::DUPLICATE_SIMILARITY) {
                $reasons[] = 'Similar name';
            }

            if ($dob !== null && $person->date_of_birth === $dob) {
                $reasons[] = 'Same date of birth';
            }

            $results[] = ['person' => $person, 'reasons' => $reasons, 'rank' => self::rank($reasons, (float) $person->getAttribute('name_score'))];
        }

        usort($results, fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        return array_map(
            fn (array $result): array => ['person' => $result['person'], 'reasons' => $result['reasons']],
            array_slice($results, 0, self::LIMIT),
        );
    }

    /**
     * Strongest first: identifiers, then name similarity, then date of
     * birth alone.
     *
     * @param  list<string>  $reasons
     */
    private static function rank(array $reasons, float $score): float
    {
        return (in_array('Same NIN', $reasons, true) ? 8 : 0)
            + (in_array('Same phone number', $reasons, true) ? 4 : 0)
            + (in_array('Similar name', $reasons, true) ? 2 + $score : 0)
            + (in_array('Same date of birth', $reasons, true) ? 1 : 0);
    }

    private static function filled(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }
}
