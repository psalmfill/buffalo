<?php

use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\CrossJurisdiction\CrossFixtures;
use Tests\Support\Jurisdiction\JurisdictionWorld;
use Tests\Support\Security\MatrixRecords;

/*
| Phase 8B.6: generated jurisdiction matrix. Every route with a
| jurisdiction-scoped model parameter is requested by each of the six roles:
|
| - with a record OUTSIDE the role's jurisdiction (state B1 for the zone A
|   DDG, the A1 State Director and Deputy, and the A1a LGA chief; for the
|   super admin, any intelligence record): every method must answer 404 or
|   403, never a page, a redirect or a validation error;
| - GET routes with a record INSIDE the jurisdiction (LGA A1a): success
|   exactly when the role holds the route's permissions in
|   RolesAndPermissionsSeeder::matrix() (ROUTE_PERMISSIONS), else 403/404.
|
| All records are `restricted`, so classification never decides. Routes
| whose parameters are not jurisdiction-scoped are listed in EXCLUDED with
| the test that covers them; a new route must be added to one or the other.
*/

const MATRIX_ROLES = ['super_admin', 'director_general', 'ddg_zone_a', 'state_director', 'deputy_state_director', 'lga_chief'];

/** Routes not in the matrix: their parameters are not jurisdiction-scoped. */
const EXCLUDED = [
    'access-requests/{accessRequest}' => 'Access request (cross-jurisdiction workflow): CrossJurisdiction/AccessRequestsTest',
    'access-requests/{accessRequest}/approve' => 'Access request: CrossJurisdiction/AccessRequestsTest',
    'access-requests/{accessRequest}/reject' => 'Access request: CrossJurisdiction/AccessRequestsTest',
    'access-requests/{accessRequest}/cancel' => 'Access request: CrossJurisdiction/AccessRequestsTest',
    'access-grants/{grant}/revoke' => 'Access grant: CrossJurisdiction/GrantsTest',
    'approval-delegations/{delegation}/revoke' => 'State delegation: CrossJurisdiction/DelegationTest',
    'disseminations/{dissemination}/acknowledge' => 'Dissemination (deliberately crosses jurisdictions): Disseminations tests',
    'disseminations/{dissemination}/onward' => 'Dissemination: Disseminations tests',
    'disseminations/{dissemination}/renew' => 'Dissemination: Disseminations tests',
    'disseminations/{dissemination}/revoke' => 'Dissemination: Disseminations tests',
    'dissemination-requests/{disseminationRequest}/decline' => 'Dissemination request: Disseminations tests',
    'organizations/{organization}' => 'National organization directory (not jurisdiction-scoped): Organizations/OrganizationsTest',
    'organizations/{organization}/edit' => 'National organization directory: Organizations/OrganizationsTest',
    'organizations/{organization}/sites' => 'National organization directory; sites are scoped and in the matrix',
    'organizations/{organization}/sites/create' => 'National organization directory: Organizations/OrganizationsTest',
    'reference-data/incident-types/{incidentType}/schema' => 'Reference data (no jurisdiction): ReferenceData tests',
    'senatorial-districts/{district}' => 'Reference data (no jurisdiction): LocalGovernment tests',
    'reports/runs/{run}/download' => 'Requester-only report file: Reports/ReportsTest',
    'reports/schedules/{schedule}' => 'Owner-only schedule: Reports/ReportsTest',
];

/**
 * Permissions each GET route needs, from the seeded matrix. Edit and create
 * pages need the module's manage permission; evidence originals need
 * evidence.download_original.
 */
const ROUTE_PERMISSIONS = [
    'users.show' => ['users.view'],
    'lgas.show' => ['lgas.view'],
    'lgas.communities.create' => ['communities.manage'],
    'lgas.religious-institutions.create' => ['religious_institutions.manage'],
    'lgas.vulnerable-population.create' => ['vulnerable_stats.manage'],
    'lgas.security-notes.create' => ['security_notes.manage'],
    'communities.show' => ['communities.view'],
    'communities.edit' => ['communities.manage'],
    'religious-institutions.edit' => ['religious_institutions.manage'],
    'vulnerable-population.edit' => ['vulnerable_stats.manage'],
    'security-notes.show' => ['security_notes.view'],
    'security-notes.edit' => ['security_notes.manage'],
    'persons.show' => ['persons.view'],
    'persons.edit' => ['persons.manage'],
    'persons.photos.show' => ['persons.view'],
    'persons.political-offices.create' => ['political.manage'],
    'political-offices.edit' => ['political.manage'],
    'persons.person-of-interest.create' => ['poi.manage'],
    'persons-of-interest.edit' => ['poi.manage'],
    'criminal-groups.show' => ['criminal_groups.view'],
    'criminal-groups.edit' => ['criminal_groups.manage'],
    'criminal-groups.area-lgas' => ['criminal_groups.manage'],
    'formations.show' => ['formations.view'],
    'formations.edit' => ['formations.manage'],
    'coverage-reports.show' => ['coverage.view'],
    'coverage-reports.edit' => ['coverage.create'],
    'organization-sites.show' => ['organizations.view'],
    'organization-sites.edit' => ['organizations.manage'],
    'incidents.show' => ['incidents.view'],
    'incidents.edit' => ['incidents.update'],
    'incidents.print' => ['incidents.view'],
    'incidents.duplicates' => ['incidents.view'],
    'evidence.show' => ['incidents.view'],
    'evidence.custody' => ['incidents.view'],
    'evidence.thumb' => ['incidents.view'],
    'evidence.preview' => ['incidents.view'],
    'evidence.stream' => ['incidents.view'],
    'evidence.download' => ['incidents.view', 'evidence.download_original'],
    'flashpoints.show' => ['flashpoints.view'],
    'flashpoints.edit' => ['flashpoints.manage'],
    'threats.show' => ['threats.view'],
    'threats.edit' => ['threats.manage'],
];

/**
 * Inside the jurisdiction, routes whose answer depends on more than the
 * permission matrix: the roles refused despite holding the permission, and
 * the workflow rule that refuses them. The matrix records were created by
 * another officer and incidents are already verified.
 */
const INSIDE_EXCEPTIONS = [
    'evidence.stream' => [MATRIX_ROLES, 'The matrix item is a photo; streams are for video and audio only'],
    'coverage-reports.edit' => [['lga_chief'], 'Chiefs edit only coverage reports they created (CoverageReportPolicy::update)'],
    'incidents.edit' => [['lga_chief'], 'After submission, edits are amendments and need incidents.amend (IncidentPolicy::update)'],
    'threats.edit' => [['lga_chief'], 'Chiefs edit only threat reports they created (ThreatReportPolicy::update)'],
];

beforeEach(function () {
    Storage::fake('evidence');
    CrossFixtures::system();
    $this->world = JurisdictionWorld::build();
    $this->inside = MatrixRecords::build($this->world->lgas['A1a'], $this->world->users['lga_chief']);
    $this->outside = MatrixRecords::build($this->world->lgas['B1'], CrossFixtures::chiefOf($this->world->lgas['B1']));
});

/** @return list<Route> */
function matrixRoutes(): array
{
    return array_values(array_filter(Router::getRoutes()->getRoutes(), fn (Route $route) => $route->signatureParameters(['subClass' => Model::class]) !== []
        && ! array_key_exists($route->uri(), EXCLUDED)));
}

function matrixMethod(Route $route): string
{
    return array_values(array_diff($route->methods(), ['HEAD']))[0];
}

/** The URL of a route with every model parameter taken from a record set. */
function matrixUrl(Route $route, MatrixRecords $set): string
{
    $values = [];
    foreach ($route->signatureParameters(['subClass' => Model::class]) as $parameter) {
        $class = (string) $parameter->getType()?->getName();
        $record = $set->get($class);
        expect($record)->not->toBeNull("No matrix record for {$class} ({$route->uri()})");
        $values[$parameter->getName()] = $record?->getRouteKey();
    }

    $uri = preg_replace_callback('/\{(\w+)(\?)?\}/', function (array $m) use ($values) {
        return match (true) {
            array_key_exists($m[1], $values) => (string) $values[$m[1]],
            $m[1] === 'kind' => 'incidents',
            ($m[2] ?? '') === '?' => '',
            // A plain string key under a scoped parent (e.g. a threat's link):
            // the parent is what is out of reach.
            $m[1] === 'link' => (string) Str::ulid(),
            default => throw new RuntimeException("No value for {{$m[1]}}"),
        };
    }, $route->uri());

    return '/'.rtrim((string) $uri, '/');
}

/** Model classes bound by a route. @return list<string> */
function matrixClasses(Route $route): array
{
    return array_map(fn ($p) => (string) $p->getType()?->getName(), $route->signatureParameters(['subClass' => Model::class]));
}

test('every model route is either in the matrix or excluded with a reason', function () {
    $modelRoutes = array_values(array_unique(array_map(fn (Route $r) => $r->uri(), array_filter(Router::getRoutes()->getRoutes(), fn (Route $r) => $r->signatureParameters(['subClass' => Model::class]) !== []))));

    foreach (array_keys(EXCLUDED) as $uri) {
        expect($modelRoutes)->toContain($uri);
    }
    foreach (matrixRoutes() as $route) {
        if (matrixMethod($route) === 'GET') {
            expect(array_key_exists((string) $route->getName(), ROUTE_PERMISSIONS))->toBeTrue("GET {$route->uri()} needs a ROUTE_PERMISSIONS entry");
        }
    }
    expect(count(matrixRoutes()))->toBeGreaterThan(100);
});

test('outside its jurisdiction, every role gets 404 or 403 on every model route', function () {
    $failures = [];
    $outsideFor = fn (string $role) => $role === 'super_admin' ? $this->inside : $this->outside;

    foreach (MATRIX_ROLES as $role) {
        if ($role === 'director_general') {
            continue; // National: nothing is outside.
        }
        $user = $this->world->users[$role];

        foreach (matrixRoutes() as $route) {
            // Super admins manage every account: user routes are theirs by design.
            if ($role === 'super_admin' && array_intersect(matrixClasses($route), [User::class]) !== []) {
                continue;
            }
            $method = matrixMethod($route);
            $status = CrossFixtures::signIn($user)->json($method, matrixUrl($route, $outsideFor($role)))->baseResponse->getStatusCode();

            if (! in_array($status, [403, 404], true)) {
                $failures[] = "{$role} {$method} {$route->uri()} => {$status}";
            }
        }
    }

    expect($failures)->toBe([]);
});

test('inside its jurisdiction, each role gets what the permission matrix grants', function () {
    $failures = [];

    foreach (MATRIX_ROLES as $role) {
        $user = $this->world->users[$role];

        foreach (matrixRoutes() as $route) {
            if (matrixMethod($route) !== 'GET') {
                continue;
            }
            $name = (string) $route->getName();
            $status = CrossFixtures::signIn($user)->get(matrixUrl($route, $this->inside))->baseResponse->getStatusCode();

            $allowed = collect(ROUTE_PERMISSIONS[$name])->every(fn (string $permission) => $user->checkPermissionTo($permission));
            if ($allowed && in_array($role, INSIDE_EXCEPTIONS[$name][0] ?? [], true)) {
                $allowed = false;
            }
            $expected = $allowed ? 'success' : 'refused'.(isset(INSIDE_EXCEPTIONS[$name]) ? ' ('.INSIDE_EXCEPTIONS[$name][1].')' : '');
            $ok = $allowed ? $status >= 200 && $status < 400 : in_array($status, [403, 404], true);

            if (! $ok) {
                $failures[] = "{$role} GET {$route->uri()} => {$status}, expected {$expected}";
            }
        }
    }

    expect($failures)->toBe([]);
});

test('the record sets sit where the matrix assumes', function () {
    expect($this->inside->get(Lga::class)?->is($this->world->lgas['A1a']))->toBeTrue()
        ->and($this->outside->get(State::class)?->is($this->world->states['B1']))->toBeTrue();
});
