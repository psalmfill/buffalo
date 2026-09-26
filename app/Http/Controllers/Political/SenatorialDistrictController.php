<?php

namespace App\Http\Controllers\Political;

use App\Enums\PoliticalOfficeType;
use App\Http\Controllers\Controller;
use App\Models\Lga;
use App\Models\PoliticalOffice;
use App\Models\SenatorialDistrict;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Political\Leadership;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Senatorial district pages (Phase 3B). Districts are reference data; they
 * are listed and bound ({district}, routes/political.php) within the
 * user's jurisdiction. Office holders and persons come through their
 * scopes (Leadership).
 */
class SenatorialDistrictController extends Controller
{
    public function index(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('viewAny', Lga::class);
        /** @var User $user */
        $user = $request->user();

        $districts = $options->districtQuery($user)
            ->with('state')
            ->join('states', 'states.id', '=', 'senatorial_districts.state_id')
            ->select('senatorial_districts.*')
            // After select(), which would otherwise drop the count.
            ->withCount(['lgas' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('states.name')
            ->orderBy('senatorial_districts.name')
            ->get();

        $senators = $user->can('viewAny', PoliticalOffice::class)
            ? PoliticalOffice::query()->current()->with('person')
                ->where('office', PoliticalOfficeType::Senator->value)
                ->whereIn('senatorial_district_id', $districts->modelKeys())
                ->get()
                ->groupBy('senatorial_district_id')
            : collect();

        return Inertia::render('senatorial-districts/index', [
            'districts' => $districts->map(fn (SenatorialDistrict $d) => [
                'ulid' => $d->ulid,
                'name' => $d->name,
                'code' => $d->code,
                'state' => ['ulid' => $d->state->ulid, 'name' => $d->state->name],
                'lga_count' => (int) $d->getAttribute('lgas_count'),
                'senators' => $senators->get($d->id)?->map(fn (PoliticalOffice $o) => ['ulid' => $o->person?->ulid, 'name' => $o->person?->name])->values()->all() ?? [],
            ])->values(),
        ]);
    }

    public function show(Request $request, SenatorialDistrict $district, Leadership $leadership): Response
    {
        $this->authorize('viewAny', Lga::class);
        /** @var User $user */
        $user = $request->user();
        $district->loadMissing('state');

        return Inertia::render('senatorial-districts/show', [
            'district' => [
                'ulid' => $district->ulid,
                'name' => $district->name,
                'code' => $district->code,
                'state' => ['ulid' => $district->state->ulid, 'name' => $district->state->name],
            ],
            ...($user->can('viewAny', PoliticalOffice::class)
                ? $leadership->forDistrict($district)
                : ['lgas' => [], 'currentHolders' => [], 'prominentPersons' => [], 'pastHolders' => []]),
            'canViewPolitical' => $user->can('viewAny', PoliticalOffice::class),
        ]);
    }
}
