<?php

namespace App\Http\Controllers\Threats;

use App\Actions\Threats\ChangeThreatStatus;
use App\Actions\Threats\SetThreatExpiry;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threats\ThreatExpiryRequest;
use App\Http\Requests\Threats\ThreatStatusRequest;
use App\Models\ThreatReport;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Threat report status, extension and reactivation (Phase 6B).
 */
class ThreatLifecycleController extends Controller
{
    public function status(ThreatStatusRequest $request, ThreatReport $threat, ChangeThreatStatus $change): RedirectResponse
    {
        $this->authorize('update', $threat);
        $incident = $request->incident();

        if ($incident !== null) {
            $this->authorize('linkIncident', [$threat, $incident]);
        }

        $changed = $change($threat, $request->status(), $request->note(), $incident, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => "{$changed->reference_no} marked {$changed->status->label()}."]);
    }

    public function extend(ThreatExpiryRequest $request, ThreatReport $threat, SetThreatExpiry $expiry): RedirectResponse
    {
        $this->authorize('update', $threat);
        $expiry->extend($threat, $request->expiresAt(), $request->comment(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Expiry extended.']);
    }

    public function reactivate(ThreatExpiryRequest $request, ThreatReport $threat, SetThreatExpiry $expiry): RedirectResponse
    {
        $this->authorize('reactivate', $threat);
        $expiry->reactivate($threat, $request->expiresAt(), $request->comment(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Threat report reactivated.']);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
