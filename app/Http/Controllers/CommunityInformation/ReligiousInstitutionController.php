<?php

namespace App\Http\Controllers\CommunityInformation;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Actions\CommunityInformation\SaveReligiousInstitution;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CommunityInformationPresenter;
use App\Http\Presenters\LgaPresenter;
use App\Http\Requests\CommunityInformation\ReligiousInstitutionRequest;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Models\Denomination;
use App\Models\Lga;
use App\Models\Religion;
use App\Models\ReligiousInstitution;
use App\Models\User;
use App\Support\Dashboard\MapView;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Religious institutions of an LGA: add, edit, delete (with a reason).
 * They are listed on the LGA and community pages (TabData).
 */
class ReligiousInstitutionController extends Controller
{
    use FormOptions;

    public function create(Request $request, Lga $lga): Response
    {
        $this->authorize('addReligiousInstitution', $lga);

        return $this->form($lga, null, (string) $request->query('community', ''));
    }

    public function store(ReligiousInstitutionRequest $request, Lga $lga, SaveReligiousInstitution $save): RedirectResponse
    {
        $this->authorize('addReligiousInstitution', $lga);

        $institution = $save->create($lga, $request->values(), $this->actor($request));

        return redirect()->route('lgas.show', ['lga' => $lga, 'tab' => 'religious_institutions'])
            ->with('toast', ['type' => 'success', 'message' => "\"{$institution->name}\" added."]);
    }

    public function edit(ReligiousInstitution $institution): Response
    {
        $this->authorize('update', $institution);

        $record = ReligiousInstitution::query()->withCoordinates()->with(['religion', 'denomination', 'community', 'lga'])->whereKey($institution->id)->firstOrFail();

        return $this->form($record->lga, $record, '');
    }

    public function update(ReligiousInstitutionRequest $request, ReligiousInstitution $institution, SaveReligiousInstitution $save): RedirectResponse
    {
        $this->authorize('update', $institution);

        $save->update($institution, $request->values(), $this->actor($request));

        return redirect()->route('lgas.show', ['lga' => $institution->lga, 'tab' => 'religious_institutions'])
            ->with('toast', ['type' => 'success', 'message' => 'Saved.']);
    }

    public function destroy(DeleteRecordRequest $request, ReligiousInstitution $institution, DeleteWithReason $delete): RedirectResponse
    {
        $this->authorize('delete', $institution);

        $delete($institution, 'religious_institution', (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('lgas.show', ['lga' => $institution->lga, 'tab' => 'religious_institutions'])
            ->with('toast', ['type' => 'success', 'message' => "\"{$institution->name}\" deleted."]);
    }

    private function form(Lga $lga, ?ReligiousInstitution $institution, string $community): Response
    {
        $lga->loadMissing(['state', 'senatorialDistrict']);
        $religions = Religion::query()->with('denominations')->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (Religion $religion) => $religion->is_active || $religion->id === $institution?->religion_id);

        return Inertia::render('religious-institutions/form', [
            'lga' => LgaPresenter::facts($lga),
            'institution' => $institution === null ? null : CommunityInformationPresenter::institution($institution),
            'communities' => $this->communityOptions($lga),
            'selectedCommunity' => $community,
            'religions' => $religions->map(fn (Religion $religion) => [
                'value' => $religion->ulid,
                'label' => $religion->name,
                'denominations' => $religion->denominations
                    ->filter(fn (Denomination $d) => $d->is_active || $d->id === $institution?->denomination_id)
                    ->sortBy('sort_order')
                    ->map(fn (Denomination $d) => ['value' => $d->ulid, 'label' => $d->name])
                    ->values(),
            ])->values(),
            'types' => CommunityInformationPresenter::options()['institutionTypes'],
            'map' => MapView::for($lga),
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
