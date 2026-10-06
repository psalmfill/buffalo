<?php

namespace App\Console\Commands;

use App\Enums\UploadKind;
use App\Models\User;
use App\Support\Geo\BoundaryImporter;
use App\Support\Malware\MalwareGuard;
use App\Support\Malware\ScannerUnavailable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * Imports LGA or state boundaries from an owner-supplied GeoJSON
 * FeatureCollection (Phase 6A.0), e.g. the Nigeria administrative
 * boundaries published by GRID3 or OCHA on HDX. Never fetches anything.
 *
 * Features are matched to our LGAs (or states) by properties
 * (--code-property, and --state-property for LGAs) or by a mapping CSV
 * (feature_id,state_code,lga_code). Everything is validated before
 * anything is written: geometry type, SRID 4326, validity (repaired and
 * reported), Nigeria's bounding box, each place matched at most once.
 * Unmatched features and places left without a boundary are listed.
 *
 * After writing, records whose location now falls outside their LGA are
 * listed in a report file (nothing is changed). See database/data/README.md.
 */
class ImportBoundaries extends Command
{
    protected $signature = 'buffalo:import-boundaries
        {file : GeoJSON FeatureCollection}
        {--level=lga : lga or state}
        {--code-property= : Feature property holding our LGA code (or state code with --level=state)}
        {--state-property= : Feature property holding our state code (LGA level)}
        {--mapping= : CSV with feature_id,state_code,lga_code instead of properties}
        {--id-property=id : Feature property used as feature_id when the feature has no "id"}
        {--attribution= : Attribution shown on maps (required)}
        {--source-name= : Name of the source (default: the file name)}
        {--user= : Email of the operator, recorded with the import}
        {--dry-run : Validate and report only}';

    protected $description = 'Import LGA or state boundaries from a GeoJSON file';

    public function handle(BoundaryImporter $importer, MalwareGuard $guard): int
    {
        $level = (string) $this->option('level');
        $file = (string) $this->argument('file');
        $attribution = trim((string) $this->option('attribution'));

        if (! in_array($level, ['lga', 'state'], true)) {
            return $this->fail('--level must be lga or state.');
        }
        if ($attribution === '') {
            return $this->fail('--attribution is required (it is shown on every map).');
        }
        if (! is_file($file)) {
            return $this->fail("File not found: {$file}");
        }

        // Scanned before anything is read (Phase 8B.4). Fails closed.
        $operator = $this->option('user') ? User::query()->where('email', (string) $this->option('user'))->first() : null;
        foreach (array_filter([$file, $this->option('mapping') ? (string) $this->option('mapping') : null]) as $scanned) {
            if (! is_file($scanned)) {
                continue;
            }
            try {
                if ($guard->inspect($scanned, UploadKind::BoundaryGeoJson, $operator)->infected) {
                    return $this->fail('The file was rejected: it contains malware. Super admins have been notified.');
                }
            } catch (ScannerUnavailable) {
                return $this->fail('Malware scanning is unavailable, so the import is refused. Start the clamav service and try again.');
            }
        }

        try {
            $collection = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return $this->fail('The file is not valid JSON: '.$e->getMessage());
        }

        if (! is_array($collection) || ($collection['type'] ?? null) !== 'FeatureCollection' || ! is_array($collection['features'] ?? null)) {
            return $this->fail('The file must be a GeoJSON FeatureCollection.');
        }
        if (isset($collection['crs']['properties']['name']) && ! preg_match('/(CRS84|EPSG:+4326)$/i', (string) $collection['crs']['properties']['name'])) {
            return $this->fail('Only WGS 84 (EPSG:4326) coordinates are accepted; the file declares '.$collection['crs']['properties']['name'].'.');
        }

        $places = $this->places($level);
        $mapping = $this->option('mapping') ? $this->mapping((string) $this->option('mapping'), $level) : null;

        if (is_string($mapping)) {
            return $this->fail($mapping);
        }
        if ($mapping === null && ! $this->option('code-property')) {
            return $this->fail('Give --code-property (and --state-property for LGAs) or --mapping.');
        }

        $errors = [];
        $repairs = [];
        $unmatched = [];
        $matched = [];

        foreach ($collection['features'] as $index => $feature) {
            $label = $this->label($feature, $index);
            $key = $this->match($feature, $level, $mapping);

            if ($key === null || ! isset($places[$key])) {
                $unmatched[] = $label.($key !== null ? " ({$key})" : '');

                continue;
            }

            $placeId = $places[$key]['id'];

            if (isset($matched[$placeId])) {
                $errors[] = "{$label}: {$places[$key]['name']} ({$key}) is matched more than once.";

                continue;
            }

            $prepared = $importer->prepare(is_array($feature['geometry'] ?? null) ? $feature['geometry'] : []);

            if (is_string($prepared)) {
                $errors[] = "{$label} ({$key}): {$prepared}";

                continue;
            }

            if ($prepared['repaired']) {
                $repairs[] = "{$label} ({$key}): repaired ({$prepared['reason']})";
            }

            $matched[$placeId] = $prepared['ewkb'];
        }

        $missing = array_values(array_map(fn (array $p) => "{$p['name']} ({$p['key']})", array_filter($places, fn (array $p) => ! isset($matched[$p['id']]))));

        $this->components->info(sprintf('%d features, %d matched, %d unmatched, %d repaired, %d errors; %d %s without a boundary.',
            count($collection['features']), count($matched), count($unmatched), count($repairs), count($errors), count($missing), $level === 'lga' ? 'LGAs' : 'states'));
        $this->listSection('Repaired geometries', $repairs);
        $this->listSection('Unmatched features', $unmatched);
        $this->listSection(($level === 'lga' ? 'LGAs' : 'States').' without a boundary', $missing);
        $this->listSection('Errors', $errors, error: true);

        if ($errors !== []) {
            $this->components->error('Nothing was written: fix the errors above.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->components->info('Dry run: nothing was written.');

            return self::SUCCESS;
        }

        $userId = $this->option('user') ? DB::table('users')->where('email', $this->option('user'))->value('id') : null;
        $result = $importer->write($level, $matched, (string) ($this->option('source-name') ?: basename($file)), $attribution, false, is_int($userId) ? $userId : null);

        $this->components->info(count($matched)." boundaries written; {$result['derived_states']} state boundaries derived from their LGAs.");
        self::report($this, $importer);

        return self::SUCCESS;
    }

    /**
     * Lists records now outside their LGA's boundary (nothing is changed).
     */
    public static function report(Command $command, BoundaryImporter $importer): void
    {
        $outside = $importer->outsideBoundaries();

        if ($outside === []) {
            $command->components->info('All existing locations lie within their LGA boundaries.');

            return;
        }

        $dir = storage_path('app/private/reports');
        @mkdir($dir, 0750, true);
        $path = $dir.'/boundary-check-'.now()->format('Ymd-His').'.csv';
        $handle = fopen($path, 'w');

        if ($handle !== false) {
            fputcsv($handle, ['table', 'ulid', 'lga', 'latitude', 'longitude']);
            foreach ($outside as $row) {
                fputcsv($handle, array_values($row));
            }
            fclose($handle);
        }

        $counts = array_count_values(array_column($outside, 'table'));
        $command->components->warn(count($outside).' existing records lie outside their LGA boundary (not changed): '
            .implode(', ', array_map(fn (string $t, int $n) => "{$t} {$n}", array_keys($counts), $counts)).'.');
        $command->components->info("Report: {$path}");
    }

    /**
     * Our LGAs (key "STATE/LGA") or states (key "STATE").
     *
     * @return array<string, array{id: int, name: string, key: string}>
     */
    private function places(string $level): array
    {
        $rows = $level === 'lga'
            ? DB::table('lgas')->join('states', 'states.id', '=', 'lgas.state_id')->get(['lgas.id', 'lgas.name', DB::raw("upper(states.code) || '/' || upper(lgas.code) AS key")])
            : DB::table('states')->get(['id', 'name', DB::raw('upper(code) AS key')]);

        $places = [];
        foreach ($rows as $row) {
            $places[(string) $row->key] = ['id' => (int) $row->id, 'name' => (string) $row->name, 'key' => (string) $row->key];
        }

        return $places;
    }

    /**
     * @return array<string, string>|string feature id => our key, or an error
     */
    private function mapping(string $path, string $level): array|string
    {
        if (! is_file($path) || ($handle = fopen($path, 'r')) === false) {
            return "Mapping file not found: {$path}";
        }

        $header = array_map(fn ($h) => strtolower(trim((string) $h)), fgetcsv($handle) ?: []);
        if (array_slice($header, 0, 3) !== ['feature_id', 'state_code', 'lga_code']) {
            fclose($handle);

            return 'The mapping CSV must start with the header feature_id,state_code,lga_code.';
        }

        $map = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($row === [null] || trim((string) ($row[0] ?? '')) === '') {
                continue;
            }
            $state = strtoupper(trim((string) ($row[1] ?? '')));
            $lga = strtoupper(trim((string) ($row[2] ?? '')));
            $map[trim((string) $row[0])] = $level === 'lga' ? "{$state}/{$lga}" : $state;
        }
        fclose($handle);

        return $map;
    }

    /**
     * Our key for a feature, or null.
     *
     * @param  array<string, string>|null  $mapping
     */
    private function match(mixed $feature, string $level, ?array $mapping): ?string
    {
        if (! is_array($feature)) {
            return null;
        }
        $properties = is_array($feature['properties'] ?? null) ? $feature['properties'] : [];

        if ($mapping !== null) {
            $id = $feature['id'] ?? $properties[(string) $this->option('id-property')] ?? null;

            return is_scalar($id) ? ($mapping[(string) $id] ?? null) : null;
        }

        $code = $properties[(string) $this->option('code-property')] ?? null;
        if (! is_scalar($code) || trim((string) $code) === '') {
            return null;
        }

        if ($level === 'state') {
            return strtoupper(trim((string) $code));
        }

        $state = $properties[(string) $this->option('state-property')] ?? null;

        return is_scalar($state) ? strtoupper(trim((string) $state)).'/'.strtoupper(trim((string) $code)) : null;
    }

    private function label(mixed $feature, int|string $index): string
    {
        $id = is_array($feature) ? ($feature['id'] ?? ($feature['properties'][(string) $this->option('id-property')] ?? null)) : null;

        return 'Feature '.(is_scalar($id) ? $id : '#'.((int) $index + 1));
    }

    /**
     * @param  list<string>  $lines
     */
    private function listSection(string $title, array $lines, bool $error = false): void
    {
        if ($lines === []) {
            return;
        }

        $this->newLine();
        $error ? $this->components->error($title) : $this->components->warn($title);
        foreach (array_slice($lines, 0, 200) as $line) {
            $this->line('  - '.$line);
        }
        if (count($lines) > 200) {
            $this->line('  … and '.(count($lines) - 200).' more');
        }
    }
}
