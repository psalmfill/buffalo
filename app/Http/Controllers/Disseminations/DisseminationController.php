<?php

namespace App\Http\Controllers\Disseminations;

use App\Actions\Disseminations\AcknowledgeDissemination;
use App\Actions\Disseminations\DeclineDisseminationRequest;
use App\Actions\Disseminations\DisseminateRecord;
use App\Actions\Disseminations\RenewDissemination;
use App\Actions\Disseminations\RequestDissemination;
use App\Actions\Disseminations\RevokeDissemination;
use App\Enums\Classification;
use App\Enums\DisseminationRequestStatus;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Presenters\DisseminationPresenter;
use App\Http\Requests\Disseminations\DecisionNoteRequest;
use App\Http\Requests\Disseminations\DisseminateRequest;
use App\Models\DisseminationRequest;
use App\Models\Flashpoint;
use App\Models\RecordDissemination;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Auth\StepUp;
use App\Support\Jurisdiction\Contracts\Disseminable;
use App\Support\Jurisdiction\DisseminationSubjects;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Dissemination of threat reports and flashpoints (Phase 7A). Records are
 * bound through the viewer's scopes; every action is authorised through
 * DisseminationPolicy. A dissemination ({dissemination}) the viewer is
 * neither owner nor recipient of is 404.
 */
class DisseminationController extends Controller
{
    private const TABS = ['received', 'sent', 'requests'];

    public function index(Request $request, DisseminationPresenter $presenter): Response
    {
        $this->authorize('viewAny', RecordDissemination::class);
        $user = $this->actor($request);
        $canRequests = $user->checkPermissionTo('disseminations.create') && in_array($user->getRole(), [Role::StateDirector, Role::DeputyStateDirector], true);
        $tab = in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'received';
        $tab = $tab === 'requests' && ! $canRequests ? 'received' : $tab;

        return Inertia::render('disseminations/index', [
            'tab' => $tab,
            'received' => $tab === 'received' ? $this->withSubjects(
                RecordDissemination::query()->receivedBy($user)
                    ->orderByRaw('CASE WHEN revoked_at IS NULL AND expires_at > now() THEN 0 ELSE 1 END')
                    ->orderByRaw('acknowledged_at IS NOT NULL')
                    ->orderByDesc('disseminated_at')
                    ->limit(200)->get(),
                $user, $presenter, received: true,
            ) : null,
            'sent' => $tab === 'sent' ? $this->withSubjects($this->sentQuery($user)->orderByDesc('disseminated_at')->limit(200)->get(), $user, $presenter, received: false) : null,
            'requests' => $tab === 'requests' ? $this->requestRows($user, $presenter) : null,
            'can' => ['requests' => $canRequests],
        ]);
    }

    public function storeThreat(DisseminateRequest $request, ThreatReport $threat, DisseminateRecord $disseminate): RedirectResponse
    {
        return $this->store($request, $threat, $disseminate);
    }

    public function storeFlashpoint(DisseminateRequest $request, Flashpoint $flashpoint, DisseminateRecord $disseminate): RedirectResponse
    {
        return $this->store($request, $flashpoint, $disseminate);
    }

    public function requestThreat(DisseminateRequest $request, ThreatReport $threat, RequestDissemination $ask): RedirectResponse
    {
        return $this->ask($request, $threat, $ask);
    }

    public function requestFlashpoint(DisseminateRequest $request, Flashpoint $flashpoint, RequestDissemination $ask): RedirectResponse
    {
        return $this->ask($request, $flashpoint, $ask);
    }

    public function onward(DisseminateRequest $request, RecordDissemination $dissemination, DisseminateRecord $disseminate): RedirectResponse
    {
        $subject = $this->subject($request, $dissemination);
        $this->authorize('onward', $dissemination);
        self::stepUpIfClassified($request, $subject);

        $rows = $disseminate($subject, $request->targets(), $request->reason(), $this->actor($request), $dissemination);

        return back()->with('toast', ['type' => 'success', 'message' => 'Forwarded to '.count($rows).' '.(count($rows) === 1 ? 'LGA' : 'LGAs').'.']);
    }

    public function acknowledge(Request $request, RecordDissemination $dissemination, AcknowledgeDissemination $acknowledge): RedirectResponse
    {
        $this->subject($request, $dissemination);
        $this->authorize('acknowledge', $dissemination);

        $acknowledge($dissemination, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Acknowledged.']);
    }

    public function renew(Request $request, RecordDissemination $dissemination, RenewDissemination $renew): RedirectResponse
    {
        $this->subject($request, $dissemination);
        $this->authorize('renew', $dissemination);

        $renew($dissemination, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Dissemination renewed.']);
    }

    public function revoke(DecisionNoteRequest $request, RecordDissemination $dissemination, RevokeDissemination $revoke): RedirectResponse
    {
        $this->subject($request, $dissemination);
        $this->authorize('revoke', $dissemination);

        $count = $revoke($dissemination, $request->note(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => $count > 1 ? "Revoked, with {$this->plural($count - 1, 'onward dissemination')}." : 'Revoked.']);
    }

    public function decline(DecisionNoteRequest $request, DisseminationRequest $disseminationRequest, DeclineDisseminationRequest $decline): RedirectResponse
    {
        $subject = $this->requestSubject($disseminationRequest);
        $this->authorize('decideRequest', $subject);

        $decline($disseminationRequest, $subject, $request->note(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Request declined.']);
    }

    /** Disseminating confidential or secret records needs a recent TOTP code (Phase 8B.2). */
    private static function stepUpIfClassified(Request $request, Model&Disseminable $subject): void
    {
        if ($subject->classificationLevel() !== Classification::Restricted) {
            StepUp::ensure($request);
        }
    }

    private function store(DisseminateRequest $request, Model&Disseminable $subject, DisseminateRecord $disseminate): RedirectResponse
    {
        $this->authorize('disseminate', $subject);
        self::stepUpIfClassified($request, $subject);
        $pending = null;

        if (is_string($request->validated('request'))) {
            $pending = DisseminationRequest::query()->where('ulid', $request->validated('request'))
                ->where('subject_type', $subject->disseminationType())->where('subject_id', $subject->getKey())
                ->where('status', DisseminationRequestStatus::Pending->value)->firstOrFail();
        }

        $rows = $disseminate($subject, $request->targets(), $request->reason(), $this->actor($request), request: $pending);

        return back()->with('toast', ['type' => 'success', 'message' => 'Shared with '.$this->plural(count($rows), 'place').'.']);
    }

    private function ask(DisseminateRequest $request, Model&Disseminable $subject, RequestDissemination $ask): RedirectResponse
    {
        $this->authorize('request', $subject);

        $ask($subject, $request->rawTargets(), $request->reason(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Request sent to the State Director and Deputy.']);
    }

    /**
     * The dissemination's record, which the viewer must be able to see
     * (owner or recipient, within clearance): otherwise 404.
     */
    private function subject(Request $request, RecordDissemination $dissemination): Model&Disseminable
    {
        $subject = DisseminationSubjects::find($dissemination);
        abort_if($subject === null || ! $this->actor($request)->can('view', $subject), 404);

        return $subject;
    }

    private function requestSubject(DisseminationRequest $request): Model&Disseminable
    {
        $subject = DisseminationSubjects::byId($request->subject_type, $request->subject_id);
        abort_if($subject === null, 404);

        return $subject;
    }

    /**
     * Disseminations made by the viewer's jurisdiction: of records they own,
     * or forwarded by their state.
     *
     * @return Builder<RecordDissemination>
     */
    private function sentQuery(User $user): Builder
    {
        $owned = fn (string $class) => $class::query()->ownJurisdiction($user)->select($class === ThreatReport::class ? 'threat_reports.id' : 'flashpoints.id');

        return RecordDissemination::query()->where(function (Builder $q) use ($owned, $user): void {
            $q->where(fn (Builder $t) => $t->where('subject_type', 'threat_report')->whereIn('subject_id', $owned(ThreatReport::class)))
                ->orWhere(fn (Builder $f) => $f->where('subject_type', 'flashpoint')->whereIn('subject_id', $owned(Flashpoint::class)))
                ->orWhere('disseminated_by', $user->id);

            if (in_array($user->getRole(), [Role::StateDirector, Role::DeputyStateDirector], true)) {
                $q->orWhereIn('parent_dissemination_id', DB::table('record_disseminations')->where('target_state_id', $user->state_id)->select('id'));
            }
        });
    }

    /**
     * Rows with their record (through the viewer's scopes): a dissemination
     * of a record the viewer cannot see is left out entirely.
     *
     * @param  Collection<int, RecordDissemination>  $rows
     * @return list<array<string, mixed>>
     */
    private function withSubjects(Collection $rows, User $user, DisseminationPresenter $presenter, bool $received): array
    {
        $rows->load(['targetState', 'targetLga', 'sourceState', 'sourceLga']);
        $threats = ThreatReport::query()->whereKey($rows->where('subject_type', 'threat_report')->pluck('subject_id')->all())->with('threatType')->get()->keyBy('id');
        $flashpoints = Flashpoint::query()->whereKey($rows->where('subject_type', 'flashpoint')->pluck('subject_id')->all())->with('threatType')->get()->keyBy('id');
        $visible = $rows->filter(fn (RecordDissemination $d) => ($d->subject_type === 'threat_report' ? $threats : $flashpoints)->has($d->subject_id))->values();
        $presented = collect($presenter->rows($visible, $user))->keyBy('ulid');

        return array_values($visible->map(function (RecordDissemination $d) use ($threats, $flashpoints, $presented, $user, $received): array {
            /** @var Model&Disseminable $subject */
            $subject = ($d->subject_type === 'threat_report' ? $threats : $flashpoints)->get($d->subject_id);

            return [
                ...$presented->get($d->ulid, []),
                'subject' => [
                    'type' => $d->subject_type,
                    'kind' => $subject->disseminationKind(),
                    'label' => $subject->disseminationLabel(),
                    'title' => $subject instanceof ThreatReport ? $subject->title : null,
                    'threat_type' => $subject->getRelationValue('threatType')?->getAttribute('name'),
                    'href' => $subject->disseminationPath(),
                ],
                'source' => $d->sourceState->name.' State',
                'source_lga' => $d->sourceLga->name,
                'can_acknowledge' => $received && $user->can('acknowledge', $d),
            ];
        })->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function requestRows(User $user, DisseminationPresenter $presenter): array
    {
        $requests = DisseminationRequest::query()
            ->orderByRaw("CASE status WHEN 'pending' THEN 0 ELSE 1 END")
            ->latest('id')->limit(200)->get();
        $rows = [];

        foreach ($requests as $request) {
            $class = RecordDissemination::subjectClass($request->subject_type);
            $subject = $class::query()->whereKey($request->subject_id)->first();

            if ($subject === null) {
                continue;
            }

            $rows[] = [
                ...$presenter->requests(new Collection([$request]), $user, $subject)[0],
                'subject' => [
                    'type' => $request->subject_type,
                    'kind' => $subject->disseminationKind(),
                    'label' => $subject->disseminationLabel(),
                    'href' => $subject->disseminationPath(),
                ],
            ];
        }

        return $rows;
    }

    private function plural(int $count, string $noun): string
    {
        return $count.' '.$noun.($count === 1 ? '' : 's');
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
