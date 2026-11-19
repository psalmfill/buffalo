<?php

namespace App\Http\Controllers\Taskings;

use App\Actions\Taskings\DiscardTaskingDraft;
use App\Actions\Taskings\RecallTasking;
use App\Actions\Taskings\RecordTaskingProgress;
use App\Actions\Taskings\SaveTaskingDraft;
use App\Actions\Taskings\SendTasking;
use App\Enums\Classification;
use App\Enums\EvidenceType;
use App\Http\Controllers\Controller;
use App\Http\Presenters\TaskingPresenter;
use App\Http\Requests\Taskings\TaskingRequest;
use App\Models\Lga;
use App\Models\State;
use App\Models\Tasking;
use App\Models\TaskingAttachment;
use App\Models\TaskingRecipient;
use App\Models\User;
use App\Support\Classification\ClassificationGate;
use App\Support\Exports\Lists\Exporters\TaskingsExporter;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\Lens\PlaceFilter;
use App\Support\Taskings\LgaChiefs;
use App\Support\Taskings\TaskingProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Taskings (Phase 11H): instructions from a State Director or Deputy to
 * the LGA Security Chiefs of their state. {tasking} is bound through the
 * jurisdiction and classification scopes (for an LGA chief: sent taskings
 * addressed to their LGA); anything out of reach is 404. One form saves a
 * draft or sends (`send`). Sent taskings are never edited: recall with a
 * reason, or add a follow-up note. Every view is access-logged; an LGA
 * chief's first view marks their LGA's row read.
 */
class TaskingController extends Controller
{
    public function index(Request $request, TaskingsExporter $exporter, JurisdictionOptions $options): Response
    {
        $this->authorize('viewAny', Tasking::class);
        $user = $this->actor($request);
        $filters = $exporter->filters($request->query(), $user);
        $boxes = TaskingsExporter::boxes($user);
        $box = $filters['box'] ?? $boxes[0];
        $isLga = TaskingsExporter::isLga($user);

        $page = $exporter->query($user, $filters)->with('state')->paginate(25)->withQueryString();
        /** @var list<int> $ids */
        $ids = array_values(array_map(fn (Tasking $t) => $t->id, $page->items()));
        $progress = $isLga ? [] : TaskingProgress::for($ids);
        $mine = $isLga ? TaskingRecipient::query()->whereIn('tasking_id', $ids)->get()->keyBy('tasking_id') : collect();
        $states = $options->states($user);

        return Inertia::render('taskings/index', [
            'taskings' => $page->through(fn (Tasking $t) => TaskingPresenter::row(
                $t,
                $isLga || $t->isDraft() ? null : ($progress[$t->id] ?? null),
                $mine->get($t->id),
                $isLga,
            )),
            'box' => $box,
            'boxes' => $boxes,
            'filters' => [
                'status' => $filters['status'] ?? '',
                'priority' => $filters['priority'] ?? '',
                'target' => $filters['target'] ?? '',
                'overdue' => $filters['overdue'] ?? '',
                'unread' => $filters['unread'] ?? '',
                'state' => $filters['state'] ?? '',
                'zone' => $filters['zone'] ?? '',
                'q' => $filters['q'] ?? '',
            ],
            'options' => TaskingPresenter::options(),
            'states' => $states->count() > 1 ? $states->map(fn (State $s) => ['value' => $s->ulid, 'label' => $s->name])->values() : [],
            'zones' => PlaceFilter::options($user),
            'isLga' => $isLga,
            'can' => ['create' => $user->can('create', Tasking::class)],
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Tasking::class);
        $user = $this->actor($request);
        $ulid = $request->query('lga');
        $preset = is_string($ulid) ? Lga::query()->where('ulid', $ulid)->first() : null;

        if ($preset !== null) {
            $this->authorize('sendTo', [Tasking::class, $preset]);
        }

        return $this->form($user, null, $preset === null ? [] : [$preset->ulid]);
    }

    public function store(TaskingRequest $request, SaveTaskingDraft $save, SendTasking $send): RedirectResponse
    {
        $this->authorize('create', Tasking::class);
        $user = $this->actor($request);
        /** @var State $state */
        $state = State::query()->findOrFail($user->state_id);
        $values = $request->values();

        $tasking = $save->create($state, $values, $user);

        if ($request->sending()) {
            $this->authorize('send', $tasking);
            $send($tasking, $values['lga_ids'], $user);

            return redirect()->route('taskings.show', $tasking)->with('toast', ['type' => 'success', 'message' => 'Tasking '.$tasking->reference_no.' sent.']);
        }

        return redirect()->route('taskings.edit', $tasking)->with('toast', ['type' => 'success', 'message' => 'Draft saved. You can now attach files.']);
    }

    public function show(Request $request, Tasking $tasking, RecordTaskingProgress $progress, LgaChiefs $chiefs): Response
    {
        $this->authorize('view', $tasking);
        $user = $this->actor($request);
        $tasking->recordAccess(context: 'view');
        Inertia::encryptHistory();
        $tasking->load('state');

        // An LGA chief: their own LGA's row (the scope returns no other).
        $mine = TaskingsExporter::isLga($user)
            ? TaskingRecipient::query()->where('tasking_id', $tasking->id)->where('lga_id', $user->lga_id)->first()
            : null;

        if ($mine !== null && $progress->read($mine, $user)) {
            $mine->refresh();
        }

        $seesAll = $user->can('viewRecipients', $tasking);
        $selected = null;

        if ($seesAll && is_string($request->query('recipient'))) {
            $row = TaskingRecipient::query()->where('tasking_id', $tasking->id)->where('ulid', $request->query('recipient'))->first();

            if ($row !== null) {
                $selected = TaskingPresenter::recipient($tasking, $row, $user);
                $tasking->recordAccess(context: 'recipient_response');
            }
        }

        $recipientLgas = $seesAll ? TaskingRecipient::query()->where('tasking_id', $tasking->id)->pluck('lga_id')->all() : [];
        $attachments = TaskingPresenter::taskingAttachments($tasking, $user);
        TaskingAttachment::query()->whereIn('ulid', array_column($attachments, 'ulid'))->get()
            ->each(fn (TaskingAttachment $a) => $a->recordAccess(context: 'tasking_page'));

        return Inertia::render('taskings/show', [
            'tasking' => TaskingPresenter::detail($tasking, ! $seesAll),
            'notes' => TaskingPresenter::notes($tasking),
            'attachments' => $attachments,
            'progress' => $seesAll && ! $tasking->isDraft() ? (function () use ($tasking) {
                $p = TaskingProgress::for([$tasking->id])[$tasking->id] ?? ['total' => 0, 'read' => 0, 'acknowledged' => 0, 'completed' => 0, 'overdue' => 0];

                return [...$p, 'line' => TaskingProgress::line($p)];
            })() : null,
            'recipients' => $seesAll ? TaskingPresenter::recipients($tasking, $chiefs->forLgas($recipientLgas)) : null,
            'selected' => $selected,
            'mine' => $mine === null ? null : [
                ...TaskingPresenter::recipient($tasking, $mine, $user),
                'can' => [
                    'respond' => $user->can('respond', $mine),
                    'upload' => $user->can('uploadToResponse', [TaskingAttachment::class, $mine]),
                ],
            ],
            'limits' => self::limits(),
            'options' => TaskingPresenter::options(),
            'can' => [
                'update' => $user->can('update', $tasking),
                'delete' => $user->can('delete', $tasking),
                'recall' => $user->can('recall', $tasking),
                'addNote' => $user->can('addNote', $tasking),
                'print' => ! $tasking->isDraft(),
            ],
        ]);
    }

    public function edit(Request $request, Tasking $tasking): Response
    {
        $this->authorize('update', $tasking);
        $ids = $tasking->draft_lga_ids ?? [];

        $chosen = array_values(array_map(fn (mixed $ulid): string => (string) $ulid, Lga::query()->whereKey($ids)->pluck('ulid')->all()));

        return $this->form($this->actor($request), $tasking, $chosen);
    }

    public function update(TaskingRequest $request, Tasking $tasking, SaveTaskingDraft $save, SendTasking $send): RedirectResponse
    {
        $this->authorize('update', $tasking);
        $user = $this->actor($request);
        $values = $request->values();

        $save->update($tasking, $values, $user);

        if ($request->sending()) {
            $this->authorize('send', $tasking);
            $send($tasking, $values['lga_ids'], $user);

            return redirect()->route('taskings.show', $tasking)->with('toast', ['type' => 'success', 'message' => 'Tasking '.$tasking->reference_no.' sent.']);
        }

        return redirect()->route('taskings.edit', $tasking)->with('toast', ['type' => 'success', 'message' => 'Draft saved.']);
    }

    public function destroy(Request $request, Tasking $tasking, DiscardTaskingDraft $discard): RedirectResponse
    {
        $this->authorize('delete', $tasking);
        $discard($tasking, $this->actor($request));

        return redirect()->route('taskings.index', ['box' => 'drafts'])->with('toast', ['type' => 'success', 'message' => 'Draft discarded.']);
    }

    public function recall(Request $request, Tasking $tasking, RecallTasking $recall): RedirectResponse
    {
        $this->authorize('recall', $tasking);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']], [
            'reason.required' => 'Give the reason for recalling the tasking; recipients will see it.',
        ]);

        $recall($tasking, trim((string) $data['reason']), $this->actor($request));

        return redirect()->route('taskings.show', $tasking)->with('toast', ['type' => 'success', 'message' => 'Tasking recalled. Recipients see your reason.']);
    }

    /**
     * The form: the recipient picker (every active LGA of the director's
     * state, with its current chief), the instruction and its settings.
     *
     * @param  list<string>  $chosen  LGA ULIDs ticked
     */
    private function form(User $user, ?Tasking $tasking, array $chosen): Response
    {
        $lgas = app(JurisdictionOptions::class)->lgaQuery($user)->active()
            ->where('lgas.state_id', $user->state_id)
            ->with('senatorialDistrict')
            ->orderBy('lgas.name')
            ->get();
        $chiefs = app(LgaChiefs::class)->forLgas(array_map('intval', $lgas->modelKeys()));
        $districts = $lgas->pluck('senatorialDistrict')->filter()->unique('id')->sortBy('name')->values();
        $gate = app(ClassificationGate::class);
        /** @var State $state */
        $state = State::query()->findOrFail($user->state_id);

        return Inertia::render('taskings/form', [
            'tasking' => $tasking === null ? null : TaskingPresenter::detail($tasking),
            'attachments' => $tasking === null ? [] : TaskingPresenter::taskingAttachments($tasking, $user),
            'state' => ['ulid' => $state->ulid, 'name' => $state->name],
            'lgas' => $lgas->map(fn (Lga $l) => [
                'ulid' => $l->ulid,
                'name' => $l->name,
                'district' => $l->senatorialDistrict?->ulid,
                'chief' => $chiefs->get($l->id)?->name,
            ])->values(),
            'districts' => $districts->map(fn ($d) => ['ulid' => $d->ulid, 'name' => $d->name])->values(),
            'chosen' => array_values(array_intersect($chosen, $lgas->pluck('ulid')->all())),
            'limits' => self::limits(),
            'options' => [
                ...TaskingPresenter::options(),
                'classifications' => array_map(fn (Classification $c) => TaskingPresenter::option($c), $gate->assignableLevels($user)),
                // Chiefs see only up to this level (the form warns above it).
                'chiefMaxLevel' => Classification::Restricted->value,
            ],
            'can' => [
                'addAttachment' => $tasking !== null && $user->can('upload', [TaskingAttachment::class, $tasking]),
                'delete' => $tasking !== null && $user->can('delete', $tasking),
            ],
        ]);
    }

    /**
     * @return array{maxCharacters: int, responseMaxCharacters: int, noteMaxCharacters: int, maxAttachments: int, imageMb: int, videoMb: int, documentMb: int}
     */
    public static function limits(): array
    {
        return [
            'maxCharacters' => (int) config('buffalo.taskings.max_characters'),
            'responseMaxCharacters' => (int) config('buffalo.taskings.response_max_characters'),
            'noteMaxCharacters' => (int) config('buffalo.taskings.note_max_characters'),
            'maxAttachments' => (int) config('buffalo.taskings.max_attachments'),
            'imageMb' => EvidenceType::Image->maxBytes() >> 20,
            'videoMb' => EvidenceType::Video->maxBytes() >> 20,
            'documentMb' => EvidenceType::Document->maxBytes() >> 20,
        ];
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
