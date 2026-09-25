<?php

namespace App\Actions\Hierarchy;

/**
 * Validated, normalised contents of the hierarchy CSV files.
 *
 * @phpstan-type DistrictRow array{line: int, state_code: string, code: string, name: string}
 * @phpstan-type LgaRow array{line: int, state_code: string, district_code: string, code: string, name: string, headquarters: string|null}
 * @phpstan-type WardRow array{line: int, state_code: string, lga_code: string, code: string, name: string}
 */
final readonly class HierarchyDataset
{
    /**
     * @param  list<DistrictRow>  $districts
     * @param  list<LgaRow>  $lgas
     * @param  list<WardRow>|null  $wards  null when wards.csv is absent
     * @param  list<string>  $errors
     * @param  list<string>  $warnings
     */
    public function __construct(
        public array $districts,
        public array $lgas,
        public ?array $wards,
        public array $errors,
        public array $warnings,
    ) {}

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }
}
