<?php

namespace App\Actions\LocalGovernment;

use App\Models\Lga;
use App\Models\User;
use App\Support\LocalGovernment\CommunityRules;
use Illuminate\Support\Facades\DB;

/**
 * Imports classified rows in ONE transaction: every `new` row, plus the
 * `duplicate` rows the user ticked ($include, by line). Errors are never
 * imported. Any failure rolls the whole import back.
 */
final class ImportCommunities
{
    public function __construct(
        private readonly ClassifyCommunityRows $classify,
        private readonly SaveCommunity $save,
        private readonly CommunityRules $rules,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $rows  rows as previewed (re-classified here)
     * @param  list<int>  $include  lines of duplicate rows to import anyway
     * @return array{imported: int, skipped: int, errors: int}
     */
    public function __invoke(Lga $lga, array $rows, array $include, User $actor): array
    {
        return DB::transaction(function () use ($lga, $rows, $include, $actor): array {
            $classified = ($this->classify)($lga, $rows);
            $imported = 0;
            $skipped = 0;
            $errors = 0;

            foreach ($classified as $row) {
                $wanted = $row['status'] === 'new'
                    || ($row['status'] === 'duplicate' && in_array($row['line'], $include, true));

                if ($row['status'] === 'error') {
                    $errors++;
                } elseif (! $wanted) {
                    $skipped++;
                } else {
                    $this->save->create($lga, [
                        'name' => (string) $row['name'],
                        'type' => (string) $row['type'],
                        'other_names' => $row['other_names'],
                        'ward_id' => $row['ward_code'] === '' ? null : $this->rules->ward($lga, 'code', (string) $row['ward_code'])?->id,
                        'latitude' => $row['latitude'] === '' ? null : (float) $row['latitude'],
                        'longitude' => $row['longitude'] === '' ? null : (float) $row['longitude'],
                        'population_estimate' => $row['population_estimate'] === '' ? null : (int) $row['population_estimate'],
                        'notes' => $row['notes'] === '' ? null : (string) $row['notes'],
                    ], $actor);
                    $imported++;
                }
            }

            return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
        });
    }
}
