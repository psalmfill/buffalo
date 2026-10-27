<?php

use App\Support\Exports\Lists\ListColumn;
use App\Support\Exports\Lists\ListExporter;
use App\Support\Exports\Lists\ListExporters;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

/*
| Phase 11E.2: every list page has an exporter, or an explicit exclusion
| with its reason. List pages are the GET index routes of BUFFALO's own
| controllers; lists shown as a tab of a record's page name that page and
| tab ("lgas.show#organizations").
*/

/** @return list<string> */
function listPageRoutes(): array
{
    return array_values(array_map(fn (Route $r) => (string) $r->getName(), array_filter(
        Router::getRoutes()->getRoutes(),
        fn (Route $r) => in_array('GET', $r->methods(), true)
            && str_ends_with((string) $r->getName(), '.index')
            && str_starts_with($r->getActionName(), 'App\\Http\\Controllers\\'),
    )));
}

test('every list page has an exporter or a justified exclusion', function () {
    $covered = array_map(fn (ListExporter $e) => explode('#', $e->page())[0], ListExporters::all());
    $missing = array_values(array_diff(listPageRoutes(), $covered, array_keys(ListExporters::EXCLUDED)));

    expect($missing)->toBe([]);
});

test('every exclusion names a registered list page and gives a reason', function () {
    $pages = listPageRoutes();

    foreach (ListExporters::EXCLUDED as $route => $reason) {
        expect($pages)->toContain($route)
            ->and(mb_strlen(trim($reason)))->toBeGreaterThan(15, "{$route} needs a reason");
    }

    // An excluded page has no exporter as well.
    $covered = array_map(fn (ListExporter $e) => explode('#', $e->page())[0], ListExporters::all());
    expect(array_intersect(array_keys(ListExporters::EXCLUDED), $covered))->toBe([]);
});

test('exporters have unique keys, real pages, and sane columns', function () {
    $keys = [];

    foreach (ListExporters::all() as $exporter) {
        [$route] = explode('#', $exporter->page());
        $columns = array_map(fn (ListColumn $c) => $c->key, $exporter->columns());

        expect(Router::has($route))->toBeTrue("{$exporter->key()}: no route {$route}")
            ->and($keys)->not->toContain($exporter->key())
            ->and($columns)->toBe(array_values(array_unique($columns)))
            ->and($exporter->defaultColumns())->not->toBeEmpty()
            ->and(array_diff($exporter->defaultColumns(), $columns))->toBe([], "{$exporter->key()}: unknown default columns")
            ->and($exporter->label())->not->toBe('');
        $keys[] = $exporter->key();
    }

    expect(count($keys))->toBe(count(ListExporters::CLASSES));
});

test('the page to exporter mapping covers the lists the phase names', function () {
    $mapping = collect(ListExporters::all())->mapWithKeys(fn (ListExporter $e) => [$e->key() => $e->page()])->all();

    expect($mapping)->toMatchArray([
        'incidents' => 'incidents.index',
        'arrests' => 'arrests.index',
        'threats' => 'threats.index',
        'flashpoints' => 'flashpoints.index',
        'blackspot_suggestions' => 'blackspot-suggestions.index',
        'coverage_reports' => 'coverage-reports.index',
        'formations' => 'formations.index',
        'organizations' => 'organizations.index',
        'organization_sites' => 'lgas.show#organizations',
        'communities' => 'communities.index',
        'religious_institutions' => 'lgas.show#religious_institutions',
        'vulnerable_population' => 'lgas.show#vulnerable_population',
        'security_notes' => 'lgas.show#security_notes',
        'states' => 'states.index',
        'lgas' => 'lgas.index',
        'senatorial_districts' => 'senatorial-districts.index',
        'political_offices' => 'lgas.show#leadership',
        'access_requests' => 'access-requests.index#oversight',
        'access_grants' => 'access-requests.index#oversight',
        'disseminations' => 'disseminations.index',
        'criminal_groups' => 'criminal-groups.index',
        'users' => 'users.index',
        'persons' => 'persons.index',
        'persons_of_interest' => 'persons-of-interest.index',
        'criminal_group_members' => 'criminal-groups.show#members',
        'organization_contacts' => 'organization-sites.show#contacts',
    ]);

    $sensitive = collect(ListExporters::all())->filter(fn (ListExporter $e) => $e->sensitive())->map(fn (ListExporter $e) => $e->key())->sort()->values()->all();
    expect($sensitive)->toBe(['criminal_group_members', 'organization_contacts', 'persons', 'persons_of_interest']);
});
