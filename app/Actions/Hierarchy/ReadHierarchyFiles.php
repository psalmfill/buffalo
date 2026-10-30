<?php

namespace App\Actions\Hierarchy;

use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Support\Csv\CsvReader;

/**
 * Reads and validates senatorial_districts.csv, lgas.csv and (optionally)
 * wards.csv. Every rule is checked before anything is written; all problems
 * are returned together with file name and line number. The rules are
 * documented in database/data/README.md.
 *
 * @phpstan-import-type DistrictRow from HierarchyDataset
 * @phpstan-import-type LgaRow from HierarchyDataset
 * @phpstan-import-type WardRow from HierarchyDataset
 */
final class ReadHierarchyFiles
{
    public const DISTRICTS_FILE = 'senatorial_districts.csv';

    public const LGAS_FILE = 'lgas.csv';

    public const WARDS_FILE = 'wards.csv';

    public const EXPECTED_STATES = HierarchyRules::EXPECTED_STATES;

    public const EXPECTED_DISTRICTS = HierarchyRules::EXPECTED_DISTRICTS;

    public const EXPECTED_LGAS = HierarchyRules::EXPECTED_LGAS;

    private const MAX_NAME_LENGTH = HierarchyRules::MAX_NAME_LENGTH;

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Every district code seen in the file (valid row or not), mapped to its
     * state, so one bad district row does not cascade into LGA errors.
     *
     * @var array<string, string>
     */
    private array $seenDistricts = [];

    /**
     * Every "<state>|<lga code>" seen in lgas.csv, for the same reason.
     *
     * @var array<string, true>
     */
    private array $seenLgas = [];

    public function __construct(private readonly CsvReader $reader) {}

    public function __invoke(string $directory, bool $allowCountMismatch = false): HierarchyDataset
    {
        $this->errors = [];
        $this->warnings = [];
        $this->seenDistricts = [];
        $this->seenLgas = [];

        $directory = rtrim($directory, '/');

        /** @var array<string, int> $stateIds */
        $stateIds = State::query()->pluck('id', 'code')->all();

        if ($stateIds === []) {
            $this->errors[] = 'No states in the database. Seed them first: php artisan db:seed --class=ZonesAndStatesSeeder';
        }

        $districtFile = $this->reader->read("{$directory}/".self::DISTRICTS_FILE, ['state_code', 'district_code', 'district_name']);
        $lgaFile = $this->reader->read("{$directory}/".self::LGAS_FILE, ['state_code', 'district_code', 'lga_code', 'lga_name', 'headquarters']);
        $wardFile = is_file("{$directory}/".self::WARDS_FILE)
            ? $this->reader->read("{$directory}/".self::WARDS_FILE, ['state_code', 'lga_code', 'ward_code', 'ward_name'])
            : null;

        array_push($this->errors, ...$districtFile->errors, ...$lgaFile->errors, ...($wardFile->errors ?? []));

        $districts = $this->validateDistricts($districtFile->rows, $stateIds);
        $lgas = $this->validateLgas($lgaFile->rows, $stateIds, $districts);
        $wards = $wardFile === null ? null : $this->validateWards($wardFile->rows, $stateIds, $lgas);

        $this->checkDatabaseConflicts($districts, $lgas, $stateIds);

        if ($districtFile->isValid() && $lgaFile->isValid()) {
            $this->checkCounts(count($stateIds), count($districtFile->rows), count($lgaFile->rows), $allowCountMismatch);
        }

        return new HierarchyDataset(
            array_values($districts),
            array_values($lgas),
            $wards === null ? null : array_values($wards),
            $this->errors,
            $this->warnings,
        );
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @param  array<string, int>  $stateIds
     * @return array<string, DistrictRow> keyed by district code
     */
    private function validateDistricts(array $rows, array $stateIds): array
    {
        $file = self::DISTRICTS_FILE;
        $districts = [];
        $names = [];

        foreach ($rows as ['line' => $line, 'values' => $values]) {
            $state = $values['state_code'];
            $code = $values['district_code'];
            $name = $values['district_name'];

            if (! $this->requireFields($file, $line, $values, ['state_code', 'district_code', 'district_name'])) {
                continue;
            }

            $this->seenDistricts[$code] ??= $state;

            $valid = $this->checkState($file, $line, $state, $stateIds);

            if (! HierarchyRules::validDistrictCode($code, $state)) {
                $this->error($file, $line, "district_code \"{$code}\" must be the state code, a hyphen and uppercase letters or digits (e.g. {$state}-C), at most 20 characters.");
                $valid = false;
            }

            $valid = $this->checkNameLength($file, $line, 'district_name', $name) && $valid;

            if (isset($districts[$code])) {
                $this->error($file, $line, "duplicate district_code \"{$code}\" (first used on line {$districts[$code]['line']}).");

                continue;
            }

            $nameKey = $state.'|'.mb_strtolower($name);

            if (isset($names[$nameKey])) {
                $this->error($file, $line, "duplicate district_name \"{$name}\" in state {$state} (first used on line {$names[$nameKey]}).");
                $valid = false;
            }

            $names[$nameKey] ??= $line;

            if ($valid) {
                $districts[$code] = ['line' => $line, 'state_code' => $state, 'code' => $code, 'name' => $name];
            }
        }

        return $districts;
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @param  array<string, int>  $stateIds
     * @param  array<string, DistrictRow>  $districts
     * @return array<string, LgaRow> keyed by "<state>|<lga code>"
     */
    private function validateLgas(array $rows, array $stateIds, array $districts): array
    {
        $file = self::LGAS_FILE;
        $lgas = [];
        $names = [];

        foreach ($rows as ['line' => $line, 'values' => $values]) {
            $state = $values['state_code'];
            $districtCode = $values['district_code'];
            $code = $values['lga_code'];
            $name = $values['lga_name'];
            $headquarters = $values['headquarters'] === '' ? null : $values['headquarters'];

            if (! $this->requireFields($file, $line, $values, ['state_code', 'district_code', 'lga_code', 'lga_name'])) {
                continue;
            }

            $this->seenLgas["{$state}|{$code}"] = true;

            $valid = $this->checkState($file, $line, $state, $stateIds);

            if (! HierarchyRules::validLgaCode($code)) {
                $this->error($file, $line, "lga_code \"{$code}\" must be exactly 3 uppercase letters.");
                $valid = false;
            }

            $districtState = $this->seenDistricts[$districtCode] ?? null;

            if ($districtState === null) {
                $this->error($file, $line, "district_code \"{$districtCode}\" is not in ".self::DISTRICTS_FILE.'.');
                $valid = false;
            } elseif ($districtState !== $state) {
                $this->error($file, $line, "district_code \"{$districtCode}\" belongs to state {$districtState}, not {$state}.");
                $valid = false;
            } elseif (! isset($districts[$districtCode])) {
                // The district row itself is invalid and already reported.
                $valid = false;
            }

            $valid = $this->checkNameLength($file, $line, 'lga_name', $name) && $valid;
            $valid = ($headquarters === null || $this->checkNameLength($file, $line, 'headquarters', $headquarters)) && $valid;

            $key = "{$state}|{$code}";

            if (isset($lgas[$key])) {
                $this->error($file, $line, "duplicate lga_code \"{$code}\" in state {$state} (first used on line {$lgas[$key]['line']}).");

                continue;
            }

            $nameKey = $state.'|'.mb_strtolower($name);

            if (isset($names[$nameKey])) {
                $this->error($file, $line, "duplicate lga_name \"{$name}\" in state {$state} (first used on line {$names[$nameKey]}).");
                $valid = false;
            }

            $names[$nameKey] ??= $line;

            if ($valid) {
                $lgas[$key] = [
                    'line' => $line,
                    'state_code' => $state,
                    'district_code' => $districtCode,
                    'code' => $code,
                    'name' => $name,
                    'headquarters' => $headquarters,
                ];
            }
        }

        return $lgas;
    }

    /**
     * @param  list<array{line: int, values: array<string, string>}>  $rows
     * @param  array<string, int>  $stateIds
     * @param  array<string, LgaRow>  $lgas
     * @return array<string, WardRow> keyed by "<state>|<lga code>|<ward code>"
     */
    private function validateWards(array $rows, array $stateIds, array $lgas): array
    {
        $file = self::WARDS_FILE;
        $wards = [];
        $names = [];

        foreach ($rows as ['line' => $line, 'values' => $values]) {
            $state = $values['state_code'];
            $lgaCode = $values['lga_code'];
            $code = $values['ward_code'];
            $name = $values['ward_name'];

            if (! $this->requireFields($file, $line, $values, ['state_code', 'lga_code', 'ward_code', 'ward_name'])) {
                continue;
            }

            $valid = $this->checkState($file, $line, $state, $stateIds);

            if (! isset($this->seenLgas["{$state}|{$lgaCode}"])) {
                $this->error($file, $line, "lga_code \"{$lgaCode}\" is not an LGA of state {$state} in ".self::LGAS_FILE.'.');
                $valid = false;
            } elseif (! isset($lgas["{$state}|{$lgaCode}"])) {
                // The LGA row itself is invalid and already reported.
                $valid = false;
            }

            if (! HierarchyRules::validWardCode($code)) {
                $this->error($file, $line, "ward_code \"{$code}\" must be 1-20 uppercase letters, digits or hyphens, starting with a letter or digit.");
                $valid = false;
            }

            $valid = $this->checkNameLength($file, $line, 'ward_name', $name) && $valid;

            $key = "{$state}|{$lgaCode}|{$code}";

            if (isset($wards[$key])) {
                $this->error($file, $line, "duplicate ward_code \"{$code}\" in LGA {$state}/{$lgaCode} (first used on line {$wards[$key]['line']}).");

                continue;
            }

            $nameKey = "{$state}|{$lgaCode}|".mb_strtolower($name);

            if (isset($names[$nameKey])) {
                $this->error($file, $line, "duplicate ward_name \"{$name}\" in LGA {$state}/{$lgaCode} (first used on line {$names[$nameKey]}).");
                $valid = false;
            }

            $names[$nameKey] ??= $line;

            if ($valid) {
                $wards[$key] = ['line' => $line, 'state_code' => $state, 'lga_code' => $lgaCode, 'code' => $code, 'name' => $name];
            }
        }

        return $wards;
    }

    /**
     * Conflicts with rows already in the database that upserting by code
     * cannot resolve.
     *
     * @param  array<string, DistrictRow>  $districts
     * @param  array<string, LgaRow>  $lgas
     * @param  array<string, int>  $stateIds
     */
    private function checkDatabaseConflicts(array $districts, array $lgas, array $stateIds): void
    {
        $stateCodes = array_flip($stateIds);

        // A district code is global: it cannot move to another state.
        $existingDistricts = SenatorialDistrict::query()
            ->whereIn('code', array_keys($districts))
            ->pluck('state_id', 'code');

        foreach ($districts as $code => $row) {
            $existingState = $existingDistricts[$code] ?? null;

            if ($existingState !== null && $existingState !== ($stateIds[$row['state_code']] ?? null)) {
                $this->error(self::DISTRICTS_FILE, $row['line'], "district_code \"{$code}\" already exists in the database for state ".($stateCodes[$existingState] ?? '?').'; a district cannot move between states.');
            }
        }

        // lgas has UNIQUE (state_id, name). An LGA that stays in the database
        // (not in the file) keeps its name, so a file LGA may not reuse it.
        $csvKeys = array_fill_keys(array_keys($lgas), true);
        $csvNames = [];

        foreach ($lgas as $row) {
            $csvNames[$row['state_code'].'|'.mb_strtolower($row['name'])] = $row;
        }

        foreach (Lga::query()->get(['state_id', 'code', 'name']) as $lga) {
            $state = $stateCodes[$lga->state_id] ?? null;

            if ($state === null || isset($csvKeys["{$state}|{$lga->code}"])) {
                continue;
            }

            $row = $csvNames[$state.'|'.mb_strtolower($lga->name)] ?? null;

            if ($row !== null) {
                $this->error(self::LGAS_FILE, $row['line'], "lga_name \"{$row['name']}\" is already used by LGA {$state}/{$lga->code} in the database, which is not in ".self::LGAS_FILE.'.');
            }
        }
    }

    private function checkCounts(int $states, int $districts, int $lgas, bool $allowCountMismatch): void
    {
        $checks = [
            ['states in the database', self::EXPECTED_STATES, $states],
            ['senatorial districts in '.self::DISTRICTS_FILE, self::EXPECTED_DISTRICTS, $districts],
            ['LGAs in '.self::LGAS_FILE, self::EXPECTED_LGAS, $lgas],
        ];

        foreach ($checks as [$what, $expected, $actual]) {
            if ($expected === $actual) {
                continue;
            }

            $message = "Expected {$expected} {$what}, found {$actual}.";

            if ($allowCountMismatch) {
                $this->warnings[] = $message.' Continuing because --allow-count-mismatch was given.';
            } else {
                $this->errors[] = $message.' Use --allow-count-mismatch to import anyway.';
            }
        }
    }

    /**
     * @param  array<string, string>  $values
     * @param  list<string>  $fields
     */
    private function requireFields(string $file, int $line, array $values, array $fields): bool
    {
        $ok = true;

        foreach ($fields as $field) {
            if ($values[$field] === '') {
                $this->error($file, $line, "{$field} is required.");
                $ok = false;
            }
        }

        return $ok;
    }

    /**
     * @param  array<string, int>  $stateIds
     */
    private function checkState(string $file, int $line, string $state, array $stateIds): bool
    {
        if (isset($stateIds[$state])) {
            return true;
        }

        $this->error($file, $line, "unknown state_code \"{$state}\".");

        return false;
    }

    private function checkNameLength(string $file, int $line, string $field, string $value): bool
    {
        if (mb_strlen($value) <= self::MAX_NAME_LENGTH) {
            return true;
        }

        $this->error($file, $line, "{$field} is longer than ".self::MAX_NAME_LENGTH.' characters.');

        return false;
    }

    private function error(string $file, int $line, string $message): void
    {
        $this->errors[] = "{$file}:{$line}: {$message}";
    }
}
