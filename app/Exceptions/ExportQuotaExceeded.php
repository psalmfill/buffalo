<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A daily export quota is used up (Phase 8B.3). JSON callers get 429;
 * pages go back with the message as an error and a toast. Viewing records
 * is never blocked.
 */
class ExportQuotaExceeded extends Exception
{
    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && $request->header('X-Inertia') === null) {
            return response()->json(['message' => $this->getMessage(), 'quota' => true], 429);
        }

        return back()->withErrors(['quota' => $this->getMessage()])->with('toast', ['type' => 'error', 'message' => $this->getMessage()]);
    }
}
