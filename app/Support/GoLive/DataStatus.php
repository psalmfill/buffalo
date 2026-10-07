<?php

namespace App\Support\GoLive;

use App\Actions\Hierarchy\ReadHierarchyFiles;
use App\Models\Community;
use App\Models\Incident;
use App\Support\Jurisdiction\JurisdictionContext;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * What production data is loaded, what is missing, and whether any
 * synthetic or demo data exists (Phase 9B.1). Used by buffalo:data:status
 * and the go-live check.
 *
 * Runs only in the system context (console commands and jobs), where the
 * jurisdiction scope sees every row; in a user's context the counts would
 * silently cover only that user's jurisdiction. Only counts and reference
 * names leave this class, never record content.
 */
final class DataStatus
{
    /**
     * Reserved and example domains (RFC 2606, RFC 6761, mDNS): accounts on
     * them are development, test or UAT accounts.
     */
    public const RESERVED_DOMAIN_PATTERN = '/(\.test|\.local|\.localhost|\.example|\.invalid|@example\.(com|net|org))$/i';

    /** Reference lists the client signs off before go-live (code => table). */
    public const REFERENCE_LISTS = [
        'incident_types' => 'Incident types and their fields',
        'threat_types' => 'Threat types',
        'security_agencies' => 'Security agencies',
        'organization_sectors' => 'Organization sectors',
        'religions' => 'Religions',
        'denominations' => 'Denominations',
        'property_types' => 'Property types',
    ];

    public function __construct(private readonly JurisdictionContext $context) {}

    /**
     * @return array{
     *     hierarchy: array{states: int, districts: int, lgas: int, wards: int, expected: array{states: int, districts: int, lgas: int}},
     *     boundaries: array{lgas_with_boundary: int, states_with_boundary: int, sources: list<array{level: string, source: string, attribution: string, synthetic: bool, features: int, imported_at: string}>},
     *     communities: int,
     *     reference: list<array{list: string, label: string, active: int, fingerprint: string}>,
     *     demo: list<array{kind: string, count: int, detail: string}>,
     *     missing: list<string>
     * }
     */
    public function build(): array
    {
        if (! $this->context->isSystem()) {
            throw new LogicException('DataStatus runs only in the system context (a console command or job).');
        }

        $hierarchy = [
            'states' => DB::table('states')->where('is_active', true)->count(),
            'districts' => DB::table('senatorial_districts')->where('is_active', true)->count(),
            'lgas' => DB::table('lgas')->where('is_active', true)->count(),
            'wards' => DB::table('wards')->where('is_active', true)->count(),
            'expected' => [
                'states' => ReadHierarchyFiles::EXPECTED_STATES,
                'districts' => ReadHierarchyFiles::EXPECTED_DISTRICTS,
                'lgas' => ReadHierarchyFiles::EXPECTED_LGAS,
            ],
        ];

        $boundaries = [
            'lgas_with_boundary' => DB::table('lgas')->where('is_active', true)->whereNotNull('boundary')->count(),
            'states_with_boundary' => DB::table('states')->where('is_active', true)->whereNotNull('boundary')->count(),
            'sources' => array_values(DB::table('boundary_sources')->orderBy('imported_at')->get()
                ->map(fn (\stdClass $row): array => [
                    'level' => (string) $row->level,
                    'source' => (string) $row->source_name,
                    'attribution' => (string) $row->attribution,
                    'synthetic' => (bool) $row->synthetic,
                    'features' => (int) $row->feature_count,
                    'imported_at' => (string) $row->imported_at,
                ])->all()),
        ];

        $missing = [];
        foreach (['states' => 'States', 'districts' => 'Senatorial districts', 'lgas' => 'LGAs'] as $level => $label) {
            if ($hierarchy[$level] < $hierarchy['expected'][$level]) {
                $missing[] = sprintf('%s: %d of %d loaded', $label, $hierarchy[$level], $hierarchy['expected'][$level]);
            }
        }
        if ($hierarchy['lgas'] === 0 || $boundaries['lgas_with_boundary'] < $hierarchy['lgas']) {
            $missing[] = sprintf('LGA boundaries: %d of %d LGAs have one', $boundaries['lgas_with_boundary'], $hierarchy['lgas']);
        }
        if ($hierarchy['wards'] === 0) {
            $missing[] = 'Wards: none loaded (optional)';
        }

        return [
            'hierarchy' => $hierarchy,
            'boundaries' => $boundaries,
            'communities' => Community::query()->count(),
            'reference' => $this->reference(),
            'demo' => $this->demo(),
            'missing' => $missing,
        ];
    }

    /**
     * Demo or synthetic data found (empty when none). Any entry blocks go-live.
     *
     * @return list<array{kind: string, count: int, detail: string}>
     */
    public function demo(): array
    {
        $found = [
            [
                'kind' => 'demo_lgas',
                'count' => DB::table('lgas')->where('name', 'like', 'Demo LGA %')->count(),
                'detail' => 'Synthetic LGAs from DevelopmentHierarchySeeder ("Demo LGA ...")',
            ],
            [
                'kind' => 'demo_districts',
                'count' => DB::table('senatorial_districts')->where('name', 'like', 'Demo District %')->count(),
                'detail' => 'Synthetic senatorial districts ("Demo District ...")',
            ],
            [
                'kind' => 'synthetic_boundaries',
                'count' => DB::table('boundary_sources')->where('synthetic', true)->count(),
                'detail' => 'Boundary imports marked synthetic (buffalo:generate-demo-boundaries)',
            ],
            [
                'kind' => 'demo_incidents',
                // Incidents are scoped; the system context sees them all.
                'count' => Incident::query()
                    ->where(fn ($query) => $query->where('reference_no', 'like', 'DEMO/%')->orWhere('title', 'like', '[Demo]%'))
                    ->count(),
                'detail' => 'Incidents from buffalo:seed-demo-incidents (DEMO/ references or "[Demo]" titles)',
            ],
            [
                'kind' => 'test_accounts',
                'count' => DB::table('users')->whereNull('deleted_at')->pluck('email')
                    ->filter(fn ($email): bool => preg_match(self::RESERVED_DOMAIN_PATTERN, (string) $email) === 1)
                    ->count(),
                'detail' => 'Accounts on reserved test domains (.test, .local, .example, ...): development, acceptance or UAT accounts',
            ],
        ];

        return array_values(array_filter($found, fn (array $item): bool => $item['count'] > 0));
    }

    /**
     * Active entries per reference list with a fingerprint of their codes,
     * names and (for incident types) field definitions: it changes whenever
     * an entry is added, renamed, retired or its fields change, so a client
     * sign-off can name exactly what was reviewed.
     *
     * @return list<array{list: string, label: string, active: int, fingerprint: string}>
     */
    public function reference(): array
    {
        $lists = [];

        foreach (self::REFERENCE_LISTS as $table => $label) {
            $columns = $table === 'incident_types' ? ['code', 'name', 'details_schema', 'schema_version'] : ['code', 'name'];
            $rows = DB::table($table)->where('is_active', true)->orderBy('code')->get($columns);

            $lists[] = [
                'list' => $table,
                'label' => $label,
                'active' => $rows->count(),
                'fingerprint' => substr(hash('sha256', (string) json_encode($rows->map(fn (\stdClass $row) => array_values((array) $row))->all())), 0, 12),
            ];
        }

        return $lists;
    }

    /**
     * One fingerprint for all reference lists together (the "reference data
     * version" recorded with the client's sign-off).
     */
    public function referenceVersion(): string
    {
        return substr(hash('sha256', implode('|', array_map(
            fn (array $list): string => $list['list'].':'.$list['fingerprint'],
            $this->reference(),
        ))), 0, 12);
    }
}
