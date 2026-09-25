<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Builds temporary directories of hierarchy CSV files for import tests.
 * See tests/fixtures/hierarchy/README.md.
 */
final class HierarchyFixtures
{
    /** @var list<string> */
    private static array $created = [];

    /**
     * Copy of fixtures/hierarchy/valid with the given overlays applied in
     * order (e.g. "invalid/bad-lga-code", "reduced").
     */
    public static function directory(string ...$overlays): string
    {
        $base = base_path('tests/fixtures/hierarchy');
        $directory = self::emptyDirectory();

        File::copyDirectory("{$base}/valid", $directory);

        foreach ($overlays as $overlay) {
            File::copyDirectory("{$base}/{$overlay}", $directory);
        }

        return $directory;
    }

    public static function emptyDirectory(): string
    {
        $directory = sys_get_temp_dir().'/buffalo-hierarchy-'.Str::lower(Str::random(12));
        File::ensureDirectoryExists($directory);
        self::$created[] = $directory;

        return $directory;
    }

    /**
     * A synthetic, full-size data set: 109 districts and 774 LGAs spread over
     * the 37 seeded states, so the count check passes without a flag.
     *
     * @param  list<string>  $stateCodes
     */
    public static function fullSizeDirectory(array $stateCodes): string
    {
        $directory = self::emptyDirectory();
        $districts = ['state_code,district_code,district_name'];
        $lgas = ['state_code,district_code,lga_code,lga_name,headquarters'];

        // 35 states x 3 + 2 states x 2 = 109 districts.
        $districtCodes = [];

        foreach ($stateCodes as $index => $state) {
            $perState = $index < 35 ? 3 : 2;

            for ($d = 1; $d <= $perState; $d++) {
                $districts[] = "{$state},{$state}-F{$d},Fixture District {$state} {$d}";
                $districtCodes[] = [$state, "{$state}-F{$d}"];
            }
        }

        // 109 x 7 + 11 = 774 LGAs; codes are unique per state.
        $perState = [];

        foreach ($districtCodes as $index => [$state, $districtCode]) {
            $count = $index < 11 ? 8 : 7;

            for ($l = 0; $l < $count; $l++) {
                $n = $perState[$state] = ($perState[$state] ?? 0) + 1;
                $code = 'F'.chr(65 + intdiv($n - 1, 26)).chr(65 + ($n - 1) % 26);
                $lgas[] = "{$state},{$districtCode},{$code},Fixture LGA {$state} {$n},";
            }
        }

        File::put("{$directory}/senatorial_districts.csv", implode("\n", $districts)."\n");
        File::put("{$directory}/lgas.csv", implode("\n", $lgas)."\n");

        return $directory;
    }

    public static function cleanUp(): void
    {
        foreach (self::$created as $directory) {
            File::deleteDirectory($directory);
        }

        self::$created = [];
    }
}
