<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A sensitive action needs a recent TOTP confirmation (Phase 8B.2).
 *
 * - Inertia visits and JSON requests get 428 Precondition Required with
 *   `{"step_up": true}`: the frontend opens the TOTP dialog and retries.
 * - Plain browser navigation (for example a download link) is redirected
 *   to the step-up page, which continues to the same address afterwards.
 */
class StepUpRequired extends Exception
{
    public function __construct()
    {
        parent::__construct('Confirm it is you: enter the code from your authenticator app.');
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->header('X-Inertia') !== null || $request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'step_up' => true], 428);
        }

        return redirect()->route('step-up.show', ['intended' => $request->getRequestUri()]);
    }
}
