<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\Auth\SessionKeys;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Mandatory two-factor enrolment: scan the QR code (generated locally by
 * Fortify), confirm a code, then see the recovery codes once and confirm
 * they were saved.
 */
class TwoFactorSetupController extends Controller
{
    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        $showRecoveryCodes = (bool) $request->session()->get(SessionKeys::SHOW_RECOVERY_CODES, false);

        if ($user->hasEnabledTwoFactorAuthentication() && ! $showRecoveryCodes) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('auth/two-factor-setup', [
            'twoFactorConfirmed' => $user->hasEnabledTwoFactorAuthentication(),
            'hasSecret' => $user->two_factor_secret !== null,
        ]);
    }

    /**
     * "I have saved these codes": the codes are not shown again.
     */
    public function complete(Request $request): RedirectResponse
    {
        $request->validate(['saved' => ['accepted']]);

        if (! $request->user()->hasEnabledTwoFactorAuthentication()) {
            return redirect()->route('two-factor.setup');
        }

        $request->session()->forget(SessionKeys::SHOW_RECOVERY_CODES);

        return redirect()->route('dashboard');
    }
}
