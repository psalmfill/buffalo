<?php

namespace App\Http\Controllers\Flashpoints;

use App\Actions\Flashpoints\AssessFlashpoint;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashpoints\AssessmentRequest;
use App\Models\Flashpoint;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Records a flashpoint assessment (Phase 6A).
 */
class FlashpointAssessmentController extends Controller
{
    public function store(AssessmentRequest $request, Flashpoint $flashpoint, AssessFlashpoint $assess): RedirectResponse
    {
        $this->authorize('assess', $flashpoint);

        /** @var User $user */
        $user = $request->user();
        $updated = $assess($flashpoint, $request->values(), $user);

        return back()->with('toast', ['type' => 'success', 'message' => "Assessment recorded. Next review by {$updated->review_due_at->format('j M Y')}."]);
    }
}
