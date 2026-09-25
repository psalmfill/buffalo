<?php

namespace App\Actions\Hierarchy;

use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\Ward;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Imports senatorial districts, LGAs and (optionally) wards from the owner's
 * CSV files.
 *
 * - Everything is validated first; on any error nothing is written.
 * - Rows are upserted by code (districts: code; LGAs: state + code; wards:
 *   LGA + code) inside a single transaction, so re-running is idempotent.
 * - Nothing is ever deleted. Records missing from the files are reported and
 *   only deactivated when asked to.
 * - A dry run computes exactly the same plan without writing.
 *
 * @phpstan-import-type DistrictRow from HierarchyDataset
 * @phpstan-import-type LgaRow from HierarchyDataset
 * @phpstan-import-type WardRow from HierarchyDataset
 */
final class ImportHierarchy
{
    public function __construct(private readonly ReadHierarchyFiles $readFiles) {}

    public function __invoke(
        string $directory,
        bool $dryRun = false,
        bool $allowCountMismatch = false,
        bool $deactivateMissing = false,
    ): HierarchyImportReport {
        $dataset = ($this->readFiles)($directory, $allowCountMismatch);

        $report = new HierarchyImportReport($dataset->errors, $dataset->warnings, $dryRun, $dataset->wards !== null);

        if ($dataset->hasErrors()) {
            return $report;
        }

        if ($dryRun) {
            $this->process($dataset, $report, write: false, deactivateMissing: $deactivateMissing);

            return $report;
        }

        DB::transaction(function () use ($dataset, $report, $deactivateMissing): void {
            // Lets a corrected file swap names between two LGAs of a state;
            // uniqueness is still checked when the transaction commits.
            DB::statement('SET CONSTRAINTS lgas_state_id_name_unique DEFERRED');

            $this->process($dataset, $report, write: true, deactivateMissing: $deactivateMissing);
        });

        $report->written = true;

        return $report;
    }

    private function process(HierarchyDataset $dataset, HierarchyImportReport $report, bool $write, bool $deactivateMissing): void
    {
        /** @var array<string, int> $stateIds */
        $stateIds = State::query()->pluck('id', 'code')->all();
        $stateCodes = array_flip($stateIds);

        $districtIds = $this->importDistricts($dataset->districts, $stateIds, $report, $write, $deactivateMissing);
        $lgaIds = $this->importLgas($dataset->lgas, $stateIds, $stateCodes, $districtIds, $report, $write, $deactivateMissing);

        if ($dataset->wards !== null) {
            $this->importWards($dataset->wards, $stateIds, $stateCodes, $lgaIds, $report, $write, $deactivateMissing);
        }
    }

    /**
     * @param  list<DistrictRow>  $rows
     * @param  array<string, int>  $stateIds
     * @return array<string, int|null> district code => id (null for a new district in a dry run)
     */
    private function importDistricts(array $rows, array $stateIds, HierarchyImportReport $report, bool $write, bool $deactivateMissing): array
    {
        $level = 'Senatorial districts';

        /** @var Collection<string, SenatorialDistrict> $existing */
        $existing = SenatorialDistrict::query()->get()->keyBy('code');
        $ids = [];

        foreach ($rows as $row) {
            [$outcome, $id] = $this->upsert(
                $existing->get($row['code']),
                SenatorialDistrict::class,
                ['code' => $row['code']],
                ['state_id' => $stateIds[$row['state_code']], 'name' => $row['name'], 'is_active' => true],
                $write,
            );

            $report->count($level, $outcome);
            $ids[$row['code']] = $id;
        }

        $this->handleMissing(
            $existing->reject(fn (SenatorialDistrict $district): bool => array_key_exists($district->code, $ids)),
            fn (SenatorialDistrict $district): string => "{$district->code} ({$district->name})",
            $level,
            $report,
            $write,
            $deactivateMissing,
        );

        return $ids;
    }

    /**
     * @param  list<LgaRow>  $rows
     * @param  array<string, int>  $stateIds
     * @param  array<int, string>  $stateCodes
     * @param  array<string, int|null>  $districtIds
     * @return array<string, int|null> "<state>|<lga code>" => id (null for a new LGA in a dry run)
     */
    private function importLgas(array $rows, array $stateIds, array $stateCodes, array $districtIds, HierarchyImportReport $report, bool $write, bool $deactivateMissing): array
    {
        $level = 'LGAs';

        /** @var Collection<string, Lga> $existing */
        $existing = Lga::query()->get()->keyBy(fn (Lga $lga): string => ($stateCodes[$lga->state_id] ?? '?')."|{$lga->code}");
        $ids = [];

        foreach ($rows as $row) {
            $key = "{$row['state_code']}|{$row['code']}";

            [$outcome, $id] = $this->upsert(
                $existing->get($key),
                Lga::class,
                ['state_id' => $stateIds[$row['state_code']], 'code' => $row['code']],
                [
                    'senatorial_district_id' => $districtIds[$row['district_code']],
                    'name' => $row['name'],
                    'headquarters' => $row['headquarters'],
                    'is_active' => true,
                ],
                $write,
            );

            $report->count($level, $outcome);
            $ids[$key] = $id;
        }

        $this->handleMissing(
            $existing->reject(fn (Lga $lga, string $key): bool => array_key_exists($key, $ids)),
            fn (Lga $lga): string => ($stateCodes[$lga->state_id] ?? '?')."/{$lga->code} ({$lga->name})",
            $level,
            $report,
            $write,
            $deactivateMissing,
        );

        return $ids;
    }

    /**
     * @param  list<WardRow>  $rows
     * @param  array<string, int>  $stateIds
     * @param  array<int, string>  $stateCodes
     * @param  array<string, int|null>  $lgaIds
     */
    private function importWards(array $rows, array $stateIds, array $stateCodes, array $lgaIds, HierarchyImportReport $report, bool $write, bool $deactivateMissing): void
    {
        $level = 'Wards';

        /** @var array<int, string> $lgaKeys lga id => "<state>|<lga code>" */
        $lgaKeys = Lga::query()->get(['id', 'state_id', 'code'])
            ->mapWithKeys(fn (Lga $lga): array => [$lga->id => ($stateCodes[$lga->state_id] ?? '?')."|{$lga->code}"])
            ->all();

        /** @var Collection<string, Ward> $existing */
        $existing = Ward::query()->get()->keyBy(fn (Ward $ward): string => ($lgaKeys[$ward->lga_id] ?? '?')."|{$ward->code}");
        $seen = [];

        foreach ($rows as $row) {
            $lgaKey = "{$row['state_code']}|{$row['lga_code']}";
            $key = "{$lgaKey}|{$row['code']}";
            $lgaId = $lgaIds[$lgaKey];

            [$outcome] = $lgaId === null
                ? ['created'] // the LGA itself is new (dry run only)
                : $this->upsert(
                    $existing->get($key),
                    Ward::class,
                    ['lga_id' => $lgaId, 'code' => $row['code']],
                    ['state_id' => $stateIds[$row['state_code']], 'name' => $row['name'], 'is_active' => true],
                    $write,
                );

            $report->count($level, $outcome);
            $seen[$key] = true;
        }

        $this->handleMissing(
            $existing->reject(fn (Ward $ward, string $key): bool => isset($seen[$key])),
            fn (Ward $ward): string => str_replace('|', '/', ($lgaKeys[$ward->lga_id] ?? '?'))."/{$ward->code} ({$ward->name})",
            $level,
            $report,
            $write,
            $deactivateMissing,
        );
    }

    /**
     * Creates or updates one record. In a dry run the outcome is computed
     * without saving (a new record has no id yet).
     *
     * @template TModel of Model
     *
     * @param  TModel|null  $model
     * @param  class-string<TModel>  $class
     * @param  array<string, mixed>  $key
     * @param  array<string, mixed>  $attributes
     * @return array{0: 'created'|'updated'|'unchanged', 1: int|null}
     */
    private function upsert(?Model $model, string $class, array $key, array $attributes, bool $write): array
    {
        if ($model === null) {
            if (! $write) {
                return ['created', null];
            }

            $created = $class::query()->create($key + $attributes);

            return ['created', (int) $created->getKey()];
        }

        $model->fill($attributes);

        if (! $model->isDirty()) {
            return ['unchanged', (int) $model->getKey()];
        }

        if ($write) {
            $model->save();
        }

        return ['updated', (int) $model->getKey()];
    }

    /**
     * Reports active records that are not in the files and, when asked,
     * deactivates them. Nothing is ever deleted.
     *
     * @template TModel of Model
     *
     * @param  Collection<string, TModel>  $missing
     * @param  callable(TModel): string  $describe
     */
    private function handleMissing(Collection $missing, callable $describe, string $level, HierarchyImportReport $report, bool $write, bool $deactivateMissing): void
    {
        foreach ($missing as $model) {
            if (! $model->getAttribute('is_active')) {
                continue;
            }

            $report->count($level, 'missing');
            $report->missing[$level][] = $describe($model);

            if ($deactivateMissing) {
                $report->count($level, 'deactivated');

                if ($write) {
                    $model->forceFill(['is_active' => false])->save();
                }
            }
        }
    }
}
