<?php

namespace App\Http\Controllers\System;

use App\Actions\GoLive\RecordGoLiveDecision;
use App\Enums\GoLiveDecision;
use App\Http\Controllers\Controller;
use App\Http\Requests\System\RecordGoLiveDecisionRequest;
use App\Models\GoLiveDecisionRecord;
use App\Models\User;
use App\Support\Auth\StepUp;
use App\Support\GoLive\GoLiveCheck;
use App\Support\Health\HealthStatus;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Go-live (Phase 9B.6), super admins: the latest go-live check (read-only;
 * it runs daily and with `buffalo:go-live:check`) and the client decisions,
 * recorded with step-up.
 */
class GoLiveController extends Controller
{
    public function index(): Response
    {
        $this->authorize('viewSystemHealth');

        $status = HealthStatus::get(GoLiveCheck::STATUS_KEY);

        return Inertia::render('system/go-live', [
            'check' => $status === null ? null : [
                'passed' => (bool) $status['ok'],
                'checked_at' => $status['at']->toIso8601String(),
                'version' => (string) ($status['data']['version'] ?? ''),
                'items' => array_values((array) ($status['data']['items'] ?? [])),
            ],
            'decisions' => array_map(fn (GoLiveDecision $decision) => [
                'value' => $decision->value,
                'label' => $decision->label(),
                'outcomes' => collect($decision->outcomes())->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
                'hint' => $decision->detailsHint(),
            ], GoLiveDecision::cases()),
            'history' => GoLiveDecisionRecord::query()->with('recorder')->latest('id')->limit(200)->get()
                ->map(fn (GoLiveDecisionRecord $record) => [
                    'ulid' => $record->ulid,
                    'decision' => $record->decision->label(),
                    'outcome' => $record->decision->outcomes()[$record->outcome] ?? $record->outcome,
                    'details' => $record->details,
                    'client_representative' => $record->client_representative,
                    'decided_on' => $record->decided_on->toDateString(),
                    'reference_version' => $record->reference_version,
                    'recorded_by' => $record->recorder->name ?? '',
                    'recorded_at' => $record->created_at->toIso8601String(),
                ])->values(),
        ]);
    }

    public function store(RecordGoLiveDecisionRequest $request, RecordGoLiveDecision $record): RedirectResponse
    {
        StepUp::ensure($request);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $decision = GoLiveDecision::from($request->string('decision')->toString());
        $record(
            $decision,
            $request->string('outcome')->toString(),
            $request->string('details')->toString(),
            $request->string('client_representative')->toString(),
            CarbonImmutable::parse($request->string('decided_on')->toString()),
            $actor,
        );

        return back()->with('toast', ['type' => 'success', 'message' => "Recorded: {$decision->label()}. The go-live check uses it from its next run."]);
    }
}
