<?php

namespace App\Http\Controllers\CommunityInformation;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Actions\CommunityInformation\SaveSecurityNote;
use App\Enums\SecurityNoteStatus;
use App\Http\Controllers\Controller;
use App\Http\Presenters\ActivityPresenter;
use App\Http\Presenters\CommunityInformationPresenter;
use App\Http\Presenters\LgaPresenter;
use App\Http\Requests\CommunityInformation\SecurityNoteRequest;
use App\Http\Requests\CommunityInformation\SecurityNoteStatusRequest;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Models\CommunitySecurityNote;
use App\Models\Lga;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

/**
 * Community security notes: classified and jurisdiction-scoped. {note} is
 * bound through both scopes, so a note outside the user's jurisdiction or
 * above their clearance is 404. Reading a confidential or secret note is
 * recorded in record_access_logs.
 */
class SecurityNoteController extends Controller
{
    use FormOptions;

    public function create(Request $request, Lga $lga): Response
    {
        $this->authorize('addSecurityNote', $lga);

        return $this->form($lga, null, (string) $request->query('community', ''));
    }

    public function store(SecurityNoteRequest $request, Lga $lga, SaveSecurityNote $save): RedirectResponse
    {
        $this->authorize('addSecurityNote', $lga);

        $note = $save->create($lga, $request->values(), $this->actor($request));

        return redirect()->route('security-notes.show', $note)
            ->with('toast', ['type' => 'success', 'message' => 'Security note recorded.']);
    }

    public function show(Request $request, CommunitySecurityNote $note): Response
    {
        $this->authorize('view', $note);
        $user = $this->actor($request);

        if ($note->requiresReadLogging()) {
            $note->recordAccess();
        }

        $note->load(['community', 'lga', 'creator']);

        return Inertia::render('security-notes/show', [
            'note' => CommunityInformationPresenter::noteDetail($note),
            'statuses' => CommunityInformationPresenter::options()['noteStatuses'],
            'history' => Activity::query()->with(['causer', 'subject'])
                ->where('subject_type', $note->getMorphClass())
                ->where('subject_id', $note->id)
                ->latest('id')
                ->limit(50)
                ->get()
                ->map(fn (Activity $activity) => ActivityPresenter::present($activity)),
            'can' => [
                'update' => $user->can('update', $note),
                'delete' => $user->can('delete', $note),
            ],
        ]);
    }

    public function edit(CommunitySecurityNote $note): Response
    {
        $this->authorize('update', $note);

        return $this->form($note->lga, $note->load('community'), '');
    }

    public function update(SecurityNoteRequest $request, CommunitySecurityNote $note, SaveSecurityNote $save): RedirectResponse
    {
        $this->authorize('update', $note);

        $save->update($note, $request->values(), $this->actor($request));

        // The note may now be above the editor's clearance (only if they
        // created it); it stays visible to them as its creator.
        return redirect()->route('security-notes.show', $note)
            ->with('toast', ['type' => 'success', 'message' => 'Security note saved.']);
    }

    public function status(SecurityNoteStatusRequest $request, CommunitySecurityNote $note, SaveSecurityNote $save): RedirectResponse
    {
        $this->authorize('update', $note);

        $save->changeStatus($note, SecurityNoteStatus::from((string) $request->validated('status')), $request->validated('resolved_at'), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Status updated.']);
    }

    public function destroy(DeleteRecordRequest $request, CommunitySecurityNote $note, DeleteWithReason $delete): RedirectResponse
    {
        $this->authorize('delete', $note);

        $delete($note, 'security_note', (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('lgas.show', ['lga' => $note->lga, 'tab' => 'security_notes'])
            ->with('toast', ['type' => 'success', 'message' => 'Security note deleted.']);
    }

    /**
     * The classification choices come from the shared auth.user
     * maxClassification (ClassificationSelect); the request re-checks them.
     */
    private function form(Lga $lga, ?CommunitySecurityNote $note, string $community): Response
    {
        $lga->loadMissing(['state', 'senatorialDistrict']);
        $options = CommunityInformationPresenter::options();

        return Inertia::render('security-notes/form', [
            'lga' => LgaPresenter::facts($lga),
            'note' => $note === null ? null : CommunityInformationPresenter::noteDetail($note->load(['lga', 'creator'])),
            'communities' => $this->communityOptions($lga),
            'selectedCommunity' => $community,
            'categories' => $options['noteCategories'],
            'statuses' => $options['noteStatuses'],
            'today' => now()->toDateString(),
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
