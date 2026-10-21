<?php

namespace App\Services\Reports\Builders;

use App\Enums\AccessAction;
use App\Enums\GrantScope;
use App\Http\Controllers\Persons\PersonController;
use App\Http\Presenters\PersonPresenter;
use App\Http\Presenters\PoiPresenter;
use App\Http\Presenters\PoliticalPresenter;
use App\Models\CriminalGroup;
use App\Models\CriminalGroupMember;
use App\Models\Person;
use App\Models\PersonAlias;
use App\Models\PersonOfInterest;
use App\Models\PoliticalOffice;
use App\Models\ReportRun;
use App\Models\User;
use App\Services\Reports\LevelTracker;
use App\Services\Reports\PdfRenderer;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportOutput;
use App\Support\Jurisdiction\GrantAccessResolver;
use App\Support\Jurisdiction\JurisdictionGate;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * One person record as a PDF (Phase 11E), exactly as the requester's page
 * shows it: the profile, including the encrypted fields (NIN, phone,
 * address, date of birth) only where the page shows them; aliases;
 * political offices, person of interest details and group memberships
 * when the requester may see them. Under a cross-jurisdiction grant, only
 * the fields the grant's scope shows on screen. No photos. Requesting it
 * needs step-up (RequestReport).
 */
final class PersonReportBuilder implements ReportBuilder
{
    public function __construct(
        private readonly PdfRenderer $pdf,
        private readonly JurisdictionGate $gate,
        private readonly GrantAccessResolver $grants,
    ) {}

    public function build(ReportRun $run, User $user): ReportOutput
    {
        $person = Person::query()->where('ulid', (string) $run->parameters['person'])->first();

        if ($person === null || ! $user->can('view', $person)) {
            throw new AuthorizationException('The person is no longer visible to the requester.');
        }

        $person->recordAccess(AccessAction::Print, 'report');
        $levels = new LevelTracker;
        $levels->see($person->classificationLevel());
        $aliases = $person->aliases()->orderBy('alias')->get()->map(fn (PersonAlias $a) => $a->alias)->all();

        if ($this->gate->viaGrantOnly($user, $person)) {
            $grant = $this->grants->activeGrant($user, $person);

            if ($grant === null) {
                throw new AuthorizationException('The grant to this person has ended.');
            }

            $fields = [...PersonController::GRANT_PROFILE_FIELDS, ...($grant->scope === GrantScope::Full ? PersonController::GRANT_FULL_EXTRA_FIELDS : [])];

            return $this->pdf->render('reports.person', [
                'person' => array_intersect_key(PersonPresenter::detail($person), array_flip($fields)),
                'underGrant' => true,
                'aliases' => $aliases,
                'offices' => null,
                'poi' => null,
                'memberships' => null,
            ], 'Person — '.$person->name, $run, $user, $levels);
        }

        $poi = $user->can('viewAny', PersonOfInterest::class)
            ? PersonOfInterest::query()->withCoordinates()->with(['lga', 'reviewer', 'person.lga', 'person.roles'])->where('person_id', $person->id)->first()
            : null;
        $poi = $poi !== null && $user->can('view', $poi) ? $poi : null;
        $memberships = $user->can('viewAny', CriminalGroup::class)
            ? CriminalGroupMember::query()->where('person_id', $person->id)->with(['group.lga', 'group.state'])->latest('id')->get()
            : null;
        $levels->seeAll(($memberships ?? collect())->map(fn (CriminalGroupMember $m) => $m->group->classificationLevel()));

        return $this->pdf->render('reports.person', [
            'person' => PersonPresenter::detail($person),
            'underGrant' => false,
            'aliases' => $aliases,
            'offices' => $user->can('viewAny', PoliticalOffice::class)
                ? PoliticalOffice::query()->where('person_id', $person->id)->with(['state', 'senatorialDistrict', 'lga', 'ward'])->orderByDesc('started_on')->get()
                    ->map(fn (PoliticalOffice $o) => [...PoliticalPresenter::office($o, withPerson: false), 'current' => $o->isCurrent()])->all()
                : null,
            'poi' => $poi === null ? null : PoiPresenter::detail($poi),
            'memberships' => $memberships?->map(fn (CriminalGroupMember $m) => [
                'group' => $m->group->name,
                'role' => $m->role_in_group,
                'status' => $m->membership_status->label(),
                'since' => $m->since?->format('j M Y'),
            ])->all(),
        ], 'Person — '.$person->name, $run, $user, $levels);
    }
}
