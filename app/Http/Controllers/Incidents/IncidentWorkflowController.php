<?php

namespace App\Http\Controllers\Incidents;

use App\Actions\Incidents\AcknowledgeEscalation;
use App\Actions\Incidents\AddIncidentUpdate;
use App\Actions\Incidents\CloseIncident;
use App\Actions\Incidents\EscalateIncident;
use App\Actions\Incidents\InvalidateIncident;
use App\Actions\Incidents\ReopenIncident;
use App\Actions\Incidents\ResolveIncident;
use App\Actions\Incidents\ReturnIncident;
use App\Actions\Incidents\StartInvestigation;
use App\Actions\Incidents\SubmitIncident;
use App\Actions\Incidents\VerifyIncident;
use App\Enums\Classification;
use App\Enums\IncidentUpdateType;
use App\Enums\InvalidReason;
use App\Http\Controllers\Controller;
use App\Http\Presenters\IncidentPresenter;
use App\Http\Requests\Incidents\IncidentTransitionRequest;
use App\Http\Requests\Incidents\IncidentUpdateRequest;
use App\Http\Requests\Incidents\InvalidateIncidentRequest;
use App\Models\Incident;
use App\Models\User;
use App\Support\Incidents\Consolidation;
use App\Support\Incidents\PossibleDuplicates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The incident workflow (Phase 5C): submission, review transitions,
 * escalation, acknowledgement and follow-ups. {incident} is bound through
 * its scopes; each step is authorised by IncidentPolicy (permission,
 * status, jurisdiction, classification and the "who" rules).
 */
class IncidentWorkflowController extends Controller
{
    /** Possible duplicates among the incidents the user can see. */
    public function duplicates(Request $request, Incident $incident, PossibleDuplicates $duplicates): JsonResponse
    {
        $this->authorize('view', $incident);

        return response()->json([
            'duplicates' => $duplicates->for($incident)->map(fn (Incident $d) => IncidentPresenter::duplicate($d))->values(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function submit(IncidentTransitionRequest $request, Incident $incident, SubmitIncident $submit): RedirectResponse
    {
        $this->authorize('submit', $incident);
        $submitted = $submit($incident, $this->actor($request), $request->boolean('confirm_duplicates'));

        return $this->done($submitted, "Submitted as {$submitted->reference_no}.");
    }

    public function return(IncidentTransitionRequest $request, Incident $incident, ReturnIncident $return): RedirectResponse
    {
        $this->authorize('return', $incident);

        return $this->done($return($incident, $this->actor($request), (string) $request->text('reason')), 'Returned to the reporting officer.');
    }

    public function verify(IncidentTransitionRequest $request, Incident $incident, VerifyIncident $verify): RedirectResponse
    {
        $this->authorize('verify', $incident);

        return $this->done($verify($incident, $this->actor($request), $request->text('comment')), 'Incident verified.');
    }

    public function startInvestigation(IncidentTransitionRequest $request, Incident $incident, StartInvestigation $start): RedirectResponse
    {
        $this->authorize('startInvestigation', $incident);

        return $this->done($start($incident, $this->actor($request), $request->text('comment')), 'Investigation started.');
    }

    public function resolve(IncidentTransitionRequest $request, Incident $incident, ResolveIncident $resolve): RedirectResponse
    {
        $this->authorize('resolve', $incident);

        return $this->done($resolve($incident, $this->actor($request), (string) $request->text('summary')), 'Incident resolved.');
    }

    public function reopen(IncidentTransitionRequest $request, Incident $incident, ReopenIncident $reopen): RedirectResponse
    {
        $this->authorize('reopen', $incident);

        return $this->done($reopen($incident, $this->actor($request), (string) $request->text('reason')), 'Incident reopened.');
    }

    public function close(IncidentTransitionRequest $request, Incident $incident, CloseIncident $close): RedirectResponse
    {
        $this->authorize('close', $incident);

        return $this->done($close($incident, $this->actor($request), $request->text('comment')), 'Incident closed. It is now locked.');
    }

    public function invalidate(InvalidateIncidentRequest $request, Incident $incident, InvalidateIncident $invalidate): RedirectResponse
    {
        $this->authorize('invalidate', $incident);
        $reason = InvalidReason::from((string) $request->validated('reason'));

        $original = $reason === InvalidReason::Duplicate ? $request->duplicateOf() : null;
        $consolidate = $original !== null && $request->boolean('consolidate');

        if ($consolidate) {
            if ($original->isLocked()) {
                throw ValidationException::withMessages(['duplicate_of' => 'The original incident is closed or invalid: it cannot receive content.']);
            }

            $this->authorize('update', $original);
        }

        $invalid = $invalidate($incident, $this->actor($request), $reason, $request->note(), $original, $consolidate, $request->validated('amendment_reason'));

        return $this->done($invalid, $consolidate
            ? "Content consolidated into {$original->reference_no}. This incident is now invalid and locked."
            : 'Incident marked invalid. It is now locked.');
    }

    /**
     * The consolidation preview (Phase 10B.4): every item on the duplicate
     * with its outcome, and both incidents' casualties side by side. JSON;
     * the original is found through the user's scopes.
     */
    public function consolidation(Request $request, Incident $incident, Consolidation $consolidation): JsonResponse
    {
        $this->authorize('invalidate', $incident);
        $original = Incident::query()->where('ulid', (string) $request->query('original'))->firstOrFail();
        abort_if($original->id === $incident->id, 404);
        $actor = $this->actor($request);

        $allowed = $actor->can('update', $original) && ! $original->isLocked();
        $plan = $allowed ? $consolidation->plan($incident, $original, $actor) : [];

        return response()->json([
            'allowed' => $allowed,
            'refusal' => $allowed ? null : ($original->isLocked() ? 'The original incident is closed or invalid: it cannot receive content.' : 'You may not edit the original incident, so its content cannot be consolidated.'),
            'requiresAmendmentReason' => $original->requiresAmendmentReason(),
            'original' => ['ulid' => $original->ulid, 'reference_no' => $original->reference_no, 'title' => $original->title],
            'duplicate' => ['ulid' => $incident->ulid, 'reference_no' => $incident->reference_no, 'title' => $incident->title],
            'items' => array_map(fn (array $item) => array_diff_key($item, ['id' => true, 'target' => true]), $plan),
            'casualties' => [
                'duplicate' => IncidentPresenter::casualtyRows($incident),
                'original' => IncidentPresenter::casualtyRows($original),
            ],
            'amendUrl' => $allowed ? route('incidents.edit', $original) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function escalate(IncidentTransitionRequest $request, Incident $incident, EscalateIncident $escalate): RedirectResponse
    {
        $this->authorize('escalate', $incident);

        return $this->done($escalate($incident, $this->actor($request), (string) $request->text('reason')), 'Escalated to national level.');
    }

    public function acknowledge(Request $request, Incident $incident, AcknowledgeEscalation $acknowledge): RedirectResponse
    {
        $this->authorize('acknowledge', $incident);

        return $this->done($acknowledge($incident, $this->actor($request)), 'Escalation acknowledged.');
    }

    public function storeUpdate(IncidentUpdateRequest $request, Incident $incident, AddIncidentUpdate $add): RedirectResponse
    {
        $this->authorize('addUpdate', $incident);

        $add(
            $incident,
            $this->actor($request),
            IncidentUpdateType::from((string) $request->validated('type')),
            trim((string) $request->validated('body')),
            Classification::from((string) $request->validated('classification')),
        );

        return redirect()->route('incidents.show', ['incident' => $incident, 'tab' => 'timeline'])
            ->with('toast', ['type' => 'success', 'message' => 'Update added.']);
    }

    private function done(Incident $incident, string $message): RedirectResponse
    {
        return redirect()->route('incidents.show', $incident)->with('toast', ['type' => 'success', 'message' => $message]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
