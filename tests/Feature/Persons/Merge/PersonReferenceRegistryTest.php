<?php

use App\Models\Person;
use App\Support\Persons\Merge\PersonReferenceRegistry;
use Illuminate\Support\Facades\DB;

/**
 * Phase 10A.1: every reference to persons in the schema is registered with
 * a merge rule, so a future module cannot be forgotten by a merge.
 */
test('every foreign key to persons is registered with a merge rule', function () {
    $columns = array_map(fn ($row) => (string) $row->ref, DB::select(<<<'SQL'
        SELECT DISTINCT c.conrelid::regclass::text || '.' || a.attname AS ref
          FROM pg_constraint c
          JOIN LATERAL unnest(c.conkey, c.confkey) AS k(local, referenced) ON true
          JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = k.local
          JOIN pg_attribute r ON r.attrelid = c.confrelid AND r.attnum = k.referenced
         WHERE c.contype = 'f' AND c.confrelid = 'persons'::regclass AND r.attname = 'id'
         ORDER BY 1
        SQL));

    expect($columns)->not->toBe([])
        ->and(array_values(array_diff($columns, array_keys(PersonReferenceRegistry::FOREIGN_KEYS))))->toBe([])
        ->and(array_values(array_diff(array_keys(PersonReferenceRegistry::FOREIGN_KEYS), $columns)))->toBe([]);
});

test('every polymorphic column pair is registered as a person reference or as never holding persons', function () {
    $pairs = array_map(fn ($row) => (string) $row->ref, DB::select(<<<'SQL'
        SELECT c.table_name || '.' || c.column_name AS ref
          FROM information_schema.columns c
         WHERE c.table_schema = 'public' AND c.column_name LIKE '%\_type'
           AND EXISTS (SELECT 1 FROM information_schema.columns d
                        WHERE d.table_schema = 'public' AND d.table_name = c.table_name
                          AND d.column_name = regexp_replace(c.column_name, '_type$', '_id'))
         ORDER BY 1
        SQL));
    $registered = [...array_keys(PersonReferenceRegistry::POLYMORPHIC), ...array_keys(PersonReferenceRegistry::NOT_PERSONS)];

    expect(array_values(array_diff($pairs, $registered)))->toBe([])
        ->and(array_values(array_diff($registered, $pairs)))->toBe([])
        ->and(array_intersect(array_keys(PersonReferenceRegistry::POLYMORPHIC), array_keys(PersonReferenceRegistry::NOT_PERSONS)))->toBe([]);
});

test('the registry detects an unregistered foreign key', function () {
    $owner = DB::connection('pgsql_migrations');

    try {
        $owner->statement('CREATE TABLE zz_registry_probe (id bigserial PRIMARY KEY, person_id bigint REFERENCES persons(id))');
        $found = $owner->selectOne(<<<'SQL'
            SELECT count(*) AS n FROM pg_constraint WHERE contype = 'f' AND confrelid = 'persons'::regclass AND conrelid = 'zz_registry_probe'::regclass
            SQL);

        expect((int) $found->n)->toBe(1)
            ->and(array_key_exists('zz_registry_probe.person_id', PersonReferenceRegistry::FOREIGN_KEYS))->toBeFalse();
    } finally {
        $owner->statement('DROP TABLE IF EXISTS zz_registry_probe');
    }
});

test('the documented inventory lists every registered reference', function () {
    $doc = (string) file_get_contents(base_path('docs/architecture/person-references.md'));

    foreach ([...array_keys(PersonReferenceRegistry::FOREIGN_KEYS), ...array_keys(PersonReferenceRegistry::POLYMORPHIC)] as $reference) {
        [$table, $column] = explode('.', $reference);
        expect(str_contains($doc, "`{$reference}") || str_contains($doc, "`.{$column}`") && str_contains($doc, "`{$table}"))->toBeTrue($reference);
    }

    expect((new Person)->getMorphClass())->toBe(Person::class);
});
