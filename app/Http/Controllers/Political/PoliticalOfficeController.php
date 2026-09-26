<?php

namespace App\Http\Controllers\Political;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Actions\Political\SavePoliticalOffice;
use App\Http\Controllers\Controller;
use App\Http\Presenters\PoliticalPresenter;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Http\Requests\Political\PoliticalOfficeRequest;
use App\Models\Lga;
use App\Models\Person;
use App\Models\PoliticalOffice;
use App\Models\User;
use App\Models\Ward;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Political offices of a person (Phase 3B). {person} and {office} are
 * bound through their scopes (anything else is 404). Overlapping holders
 * of a single seat are shown as a warning after saving, never refused.
 */
class PoliticalOfficeController extends Controller
{
    public function create(Request $request, Person $person, JurisdictionOptions $options): Response
    {
        $this->authorize('create', [PoliticalOffice::class, $person]);

        return $this->form($request, $options, $person, null);
    }

    public function store(PoliticalOfficeRequest $request, Person $person, SavePoliticalOffice $save): RedirectResponse
    {
        $this->authorize('create', [PoliticalOffice::class, $person]);

        $result = $save->create($person, $request->values(), $this->actor($request));

        return $this->saved($person, $result['warnings'], 'Office recorded.');
    }

    public function edit(Request $request, PoliticalOffice $office, JurisdictionOptions $options): Response
    {
        $this->authorize('update', $office);

        /** @var Person $person */
        $person = Person::query()->findOrFail($office->person_id);

        return $this->form($request, $options, $person, $office);
    }

    public function update(PoliticalOfficeRequest $request, PoliticalOffice $office, SavePoliticalOffice $save): RedirectResponse
    {
        $this->authorize('update', $office);

        $result = $save->update($office, $request->values(), $this->actor($request));

        return $this->saved(Person::query()->findOrFail($office->person_id), $result['warnings'], 'Office saved.');
    }

    public function destroy(DeleteRecordRequest $request, PoliticalOffice $office, DeleteWithReason $delete): RedirectResponse
    {
        $this->authorize('delete', $office);

        $person = Person::query()->findOrFail($office->person_id);
        $delete($office, 'political_office', (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('persons.show', ['person' => $person, 'tab' => 'political_offices'])
            ->with('toast', ['type' => 'success', 'message' => 'Office deleted.']);
    }

    /**
     * @param  list<string>  $warnings
     */
    private function saved(Person $person, array $warnings, string $message): RedirectResponse
    {
        return redirect()->route('persons.show', ['person' => $person, 'tab' => 'political_offices'])
            ->with('toast', $warnings === []
                ? ['type' => 'success', 'message' => $message]
                : ['type' => 'warning', 'message' => $message.' '.implode(' ', $warnings)]);
    }

    private function form(Request $request, JurisdictionOptions $options, Person $person, ?PoliticalOffice $office): Response
    {
        $user = $this->actor($request);
        $office?->loadMissing(['state', 'senatorialDistrict', 'lga', 'ward', 'person']);

        $states = $options->states($user);
        $stateUlid = $request->query('state', $office?->state->ulid ?? ($states->count() === 1 ? $states->first()?->ulid : null));
        $state = is_string($stateUlid) ? $states->firstWhere('ulid', $stateUlid) : null;
        $lgas = $state === null ? collect() : $options->lgas($user, $state);
        $lgaUlid = $request->query('lga', ($office !== null ? $office->lga?->ulid : null) ?? ($lgas->count() === 1 ? $lgas->first()?->ulid : null));
        $lga = is_string($lgaUlid) ? $lgas->firstWhere('ulid', $lgaUlid) : null;

        return Inertia::render('political-offices/form', [
            'person' => ['ulid' => $person->ulid, 'name' => $person->name],
            'office' => $office === null ? null : PoliticalPresenter::office($office, withPerson: false),
            'officeTypes' => PoliticalPresenter::officeTypes(),
            'states' => $states->map(fn ($s) => ['value' => $s->ulid, 'label' => $s->name])->values(),
            'selectedState' => $state?->ulid,
            'districts' => $state === null ? [] : $options->districtQuery($user)->where('state_id', $state->id)->orderBy('name')->get()
                ->map(fn ($d) => ['value' => $d->ulid, 'label' => $d->name])->values(),
            'lgas' => $lgas->map(fn (Lga $l) => ['value' => $l->ulid, 'label' => $l->name])->values(),
            'selectedLga' => $lga?->ulid,
            'wards' => $lga === null ? [] : Ward::query()->where('lga_id', $lga->id)->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Ward $w) => ['value' => $w->ulid, 'label' => "{$w->name} ({$w->code})"])->values(),
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
