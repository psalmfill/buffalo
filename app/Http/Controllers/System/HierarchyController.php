<?php

namespace App\Http\Controllers\System;

use App\Actions\Hierarchy\Admin\ChangeStateCapital;
use App\Actions\Hierarchy\Admin\CreateLga;
use App\Actions\Hierarchy\Admin\CreateSenatorialDistrict;
use App\Actions\Hierarchy\Admin\CreateWard;
use App\Actions\Hierarchy\Admin\HierarchyChange;
use App\Actions\Hierarchy\Admin\RenameHierarchyEntry;
use App\Actions\Hierarchy\Admin\SetHierarchyEntryActive;
use App\Http\Controllers\Controller;
use App\Http\Requests\Hierarchy\HierarchyChangeRequest;
use App\Http\Requests\Hierarchy\RenameHierarchyEntryRequest;
use App\Http\Requests\Hierarchy\StateCapitalRequest;
use App\Http\Requests\Hierarchy\StoreHierarchyEntryRequest;
use App\Models\Lga;
use App\Models\SenatorialDistrict;
use App\Models\State;
use App\Models\User;
use App\Models\Ward;
use App\Support\Auth\StepUp;
use App\Support\Hierarchy\HierarchyTotals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Administration › Hierarchy (CRUD coverage step), for super admins and the
 * DG (StatePolicy::manageHierarchy):
 *
 * - states: edit the capital only (exactly 37, never created or deleted);
 * - senatorial districts, LGAs and wards: create, rename and deactivate or
 *   reactivate, never delete. Codes are set on create and never change:
 *   they are the CSV import's matching key and appear in incident
 *   references.
 *
 * Every change needs step-up and a reason, and is a named audit event.
 * Hierarchy entries are national reference data (not jurisdiction-scoped),
 * so entries are bound by ULID without a jurisdiction check.
 */
class HierarchyController extends Controller
{
    public function index(): Response
    {
        $this->authorize('manageHierarchy', State::class);

        $states = State::query()
            ->with('geopoliticalZone')
            ->withCount([
                'senatorialDistricts as districts_active' => fn ($q) => $q->where('is_active', true),
                'senatorialDistricts as districts_inactive' => fn ($q) => $q->where('is_active', false),
            ])
            ->orderBy('name')
            ->get();

        $lgaCounts = Lga::query()
            ->selectRaw('state_id, count(*) filter (where is_active) as active, count(*) filter (where not is_active) as inactive')
            ->groupBy('state_id')
            ->get()
            ->keyBy('state_id');

        $onScreen = SenatorialDistrict::query()->where('origin', 'admin')->pluck('state_id')
            ->merge(Lga::query()->where('origin', 'admin')->pluck('state_id'))
            ->merge(Ward::query()->where('origin', 'admin')->pluck('state_id'))
            ->countBy();

        return Inertia::render('system/hierarchy/index', [
            'states' => $states->map(fn (State $state) => [
                'ulid' => $state->ulid,
                'code' => $state->code,
                'name' => $state->name,
                'zone' => $state->geopoliticalZone->name,
                'capital' => $state->capital,
                'districts' => ['active' => (int) $state->getAttribute('districts_active'), 'inactive' => (int) $state->getAttribute('districts_inactive')],
                'lgas' => ['active' => (int) ($lgaCounts->get($state->id)?->getAttribute('active') ?? 0), 'inactive' => (int) ($lgaCounts->get($state->id)?->getAttribute('inactive') ?? 0)],
                'created_on_screen' => (int) ($onScreen->get($state->id) ?? 0),
            ])->values(),
            'totals' => HierarchyTotals::current(),
            'warnings' => HierarchyTotals::warnings(),
        ]);
    }

    public function state(State $state): Response
    {
        $this->authorize('manageHierarchy', State::class);

        $districts = $state->senatorialDistricts()->with('creator')->orderBy('name')->get();
        $lgas = Lga::query()->where('state_id', $state->id)
            ->with(['creator', 'senatorialDistrict'])
            ->withCount(['wards as wards_active' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        return Inertia::render('system/hierarchy/state', [
            'state' => $this->stateSummary($state),
            'districts' => $districts->map(fn (SenatorialDistrict $district) => [
                ...$this->entry($district),
                'lgas_active' => $lgas->where('senatorial_district_id', $district->id)->where('is_active', true)->count(),
            ])->values(),
            'lgas' => $lgas->map(fn (Lga $lga) => [
                ...$this->entry($lga),
                'headquarters' => $lga->headquarters,
                'district' => ['ulid' => $lga->senatorialDistrict->ulid, 'name' => $lga->senatorialDistrict->name, 'is_active' => $lga->senatorialDistrict->is_active],
                'wards_active' => (int) $lga->getAttribute('wards_active'),
            ])->values(),
            'warnings' => HierarchyTotals::warnings(),
        ]);
    }

    public function lga(Lga $adminLga): Response
    {
        $this->authorize('manageHierarchy', State::class);

        $adminLga->loadMissing(['state', 'senatorialDistrict']);
        $wards = $adminLga->wards()->with('creator')->orderBy('name')->get();

        return Inertia::render('system/hierarchy/lga', [
            'state' => $this->stateSummary($adminLga->state),
            'lga' => [
                ...$this->entry($adminLga),
                'headquarters' => $adminLga->headquarters,
                'district' => ['ulid' => $adminLga->senatorialDistrict->ulid, 'name' => $adminLga->senatorialDistrict->name, 'is_active' => $adminLga->senatorialDistrict->is_active],
            ],
            'wards' => $wards->map(fn (Ward $ward) => $this->entry($ward))->values(),
        ]);
    }

    public function updateCapital(StateCapitalRequest $request, State $state, ChangeStateCapital $change): RedirectResponse
    {
        StepUp::ensure($request);
        $change($state, $request->text('capital'), $request->reason(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => "Capital of {$state->name} saved."]);
    }

    public function storeDistrict(StoreHierarchyEntryRequest $request, State $state, CreateSenatorialDistrict $create): RedirectResponse
    {
        StepUp::ensure($request);
        $district = $create($state, (string) $request->text('code'), (string) $request->text('name'), $request->reason(), $this->actor($request));

        return $this->changed("Senatorial district {$district->name} ({$district->code}) created.");
    }

    public function storeLga(StoreHierarchyEntryRequest $request, State $state, CreateLga $create): RedirectResponse
    {
        StepUp::ensure($request);
        $district = SenatorialDistrict::query()->where('ulid', (string) $request->text('district'))->first()
            ?? throw ValidationException::withMessages(['district' => 'Choose the senatorial district.']);

        $lga = $create($state, $district, (string) $request->text('code'), (string) $request->text('name'), $request->text('headquarters'), $request->reason(), $this->actor($request));

        return $this->changed("LGA {$lga->name} ({$lga->code}) created.");
    }

    public function storeWard(StoreHierarchyEntryRequest $request, Lga $adminLga, CreateWard $create): RedirectResponse
    {
        StepUp::ensure($request);
        $ward = $create($adminLga, (string) $request->text('code'), (string) $request->text('name'), $request->reason(), $this->actor($request));

        return $this->changed("Ward {$ward->name} ({$ward->code}) created.");
    }

    public function renameDistrict(RenameHierarchyEntryRequest $request, SenatorialDistrict $adminDistrict, RenameHierarchyEntry $rename): RedirectResponse
    {
        return $this->rename($request, $adminDistrict, $rename);
    }

    public function renameLga(RenameHierarchyEntryRequest $request, Lga $adminLga, RenameHierarchyEntry $rename): RedirectResponse
    {
        return $this->rename($request, $adminLga, $rename);
    }

    public function renameWard(RenameHierarchyEntryRequest $request, Ward $adminWard, RenameHierarchyEntry $rename): RedirectResponse
    {
        return $this->rename($request, $adminWard, $rename);
    }

    public function districtActive(HierarchyChangeRequest $request, SenatorialDistrict $adminDistrict, SetHierarchyEntryActive $set): RedirectResponse
    {
        return $this->setActive($request, $adminDistrict, $set);
    }

    public function lgaActive(HierarchyChangeRequest $request, Lga $adminLga, SetHierarchyEntryActive $set): RedirectResponse
    {
        return $this->setActive($request, $adminLga, $set);
    }

    public function wardActive(HierarchyChangeRequest $request, Ward $adminWard, SetHierarchyEntryActive $set): RedirectResponse
    {
        return $this->setActive($request, $adminWard, $set);
    }

    private function rename(RenameHierarchyEntryRequest $request, SenatorialDistrict|Lga|Ward $entry, RenameHierarchyEntry $rename): RedirectResponse
    {
        StepUp::ensure($request);
        $rename($entry, (string) $request->text('name'), $request->text('headquarters'), $request->reason(), $this->actor($request));

        return $this->changed(HierarchyChange::label($entry).' '.HierarchyChange::describe($entry).' saved.');
    }

    private function setActive(HierarchyChangeRequest $request, SenatorialDistrict|Lga|Ward $entry, SetHierarchyEntryActive $set): RedirectResponse
    {
        StepUp::ensure($request);
        $request->validate(['active' => ['required', 'boolean']]);
        $active = $request->boolean('active');
        $set($entry, $active, $request->reason(), $this->actor($request));

        return $this->changed(HierarchyChange::label($entry).' '.HierarchyChange::describe($entry).($active ? ' reactivated.' : ' deactivated.'));
    }

    /** Back to the page, with the expected-count warning when totals differ. */
    private function changed(string $message): RedirectResponse
    {
        $warnings = HierarchyTotals::warnings();

        return back()->with('toast', $warnings === []
            ? ['type' => 'success', 'message' => $message]
            : ['type' => 'warning', 'message' => $message.' '.implode(' ', $warnings)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function stateSummary(State $state): array
    {
        return ['ulid' => $state->ulid, 'code' => $state->code, 'name' => $state->name, 'capital' => $state->capital];
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(SenatorialDistrict|Lga|Ward $entry): array
    {
        /** @var User|null $creator */
        $creator = $entry->creator;

        return [
            'ulid' => $entry->ulid,
            'code' => $entry->code,
            'name' => $entry->name,
            'is_active' => $entry->is_active,
            'created_on_screen' => $entry->createdOnScreen(),
            'created_by' => $entry->createdOnScreen() ? $creator?->name : null,
            'created_at' => $entry->createdOnScreen() ? $this->timestamp($entry) : null,
            'created_reason' => $entry->createdOnScreen() ? $entry->created_reason : null,
        ];
    }

    private function timestamp(Model $entry): ?string
    {
        $at = $entry->getAttribute('created_at');

        return $at instanceof \DateTimeInterface ? $at->format(DATE_ATOM) : null;
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
