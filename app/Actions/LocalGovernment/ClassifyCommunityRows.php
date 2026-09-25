<?php

namespace App\Actions\LocalGovernment;

use App\Enums\CommunityType;
use App\Models\Lga;
use App\Support\LocalGovernment\CommunityRules;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Validates community import rows for an LGA and classifies each one:
 *
 * - `error`: invalid, a name already used in the LGA, or repeated in the
 *   file (with reasons);
 * - `duplicate`: valid, but similar (trigram similarity >= 0.6) to an
 *   existing community of the LGA (with the match); imported only if the
 *   user ticks it;
 * - `new`: valid and not similar to anything.
 *
 * Runs at preview and again at confirmation (the server never trusts the
 * previewed result).
 */
final class ClassifyCommunityRows
{
    public const DUPLICATE_SIMILARITY = 0.6;

    public function __construct(private readonly CommunityRules $rules) {}

    /**
     * @param  list<array<string, mixed>>  $rows  CSV rows (strings) or previously classified rows
     * @return list<array<string, mixed>>
     */
    public function __invoke(Lga $lga, array $rows): array
    {
        $seen = [];
        $result = [];

        foreach ($rows as $row) {
            $classified = $this->classify($lga, $row);

            $key = mb_strtolower((string) $classified['name']);
            if ($classified['status'] !== 'error' && isset($seen[$key])) {
                $classified['status'] = 'error';
                $classified['errors'][] = "The name is repeated in this file (line {$seen[$key]}).";
            }
            $seen[$key] ??= $classified['line'];

            $result[] = $classified;
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function classify(Lga $lga, array $row): array
    {
        $values = [
            'line' => (int) ($row['line'] ?? 0),
            'name' => self::text($row['name'] ?? null),
            'type' => strtolower(self::text($row['type'] ?? null)),
            'ward_code' => self::text($row['ward_code'] ?? null),
            'latitude' => self::text($row['latitude'] ?? null),
            'longitude' => self::text($row['longitude'] ?? null),
            'population_estimate' => self::text($row['population_estimate'] ?? null),
            'other_names' => self::otherNames($row['other_names'] ?? null),
            'notes' => self::text($row['notes'] ?? null),
        ];

        $validator = Validator::make($values, [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(CommunityType::class)],
            'ward_code' => ['nullable', 'string', 'max:20'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'population_estimate' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'other_names' => ['array', 'max:20'],
            'other_names.*' => ['string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [], [
            'ward_code' => 'ward code',
            'population_estimate' => 'population estimate',
            'other_names' => 'other names',
        ]);

        $errors = array_values(array_unique($validator->errors()->all()));

        if ($errors === []) {
            if ($values['ward_code'] !== '') {
                if ($this->rules->ward($lga, 'code', $values['ward_code']) === null) {
                    $errors[] = "Ward \"{$values['ward_code']}\" is not a ward of {$lga->name} LGA.";
                }
            }

            if ($values['latitude'] !== '') {
                $location = $this->rules->locationError($lga, (float) $values['latitude'], (float) $values['longitude']);
                if ($location !== null) {
                    $errors[] = $location;
                }
            }

            $existing = $this->rules->sameName($lga, $values['name']);
            if ($existing !== null) {
                $errors[] = "A community named \"{$existing->name}\" already exists in this LGA.";
            }
        }

        $match = null;
        if ($errors === []) {
            $similar = $this->rules->similar($lga, $values['name'], self::DUPLICATE_SIMILARITY);
            if ($similar !== null) {
                $match = [
                    'ulid' => $similar['community']->ulid,
                    'name' => $similar['community']->name,
                    'similarity' => $similar['similarity'],
                ];
            }
        }

        // No ward id here: rows go to the browser (never numeric ids).
        return [
            ...$values,
            'status' => $errors !== [] ? 'error' : ($match !== null ? 'duplicate' : 'new'),
            'errors' => $errors,
            'match' => $match,
        ];
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @return list<string>
     */
    private static function otherNames(mixed $value): array
    {
        $names = is_array($value) ? $value : explode('|', self::text($value));

        return array_values(array_filter(
            array_map(fn (mixed $name): string => self::text($name), $names),
            fn (string $name): bool => $name !== '',
        ));
    }
}
