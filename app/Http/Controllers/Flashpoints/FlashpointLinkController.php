<?php

namespace App\Http\Controllers\Flashpoints;

use App\Actions\Flashpoints\LinkToFlashpoint;
use App\Actions\Flashpoints\UnlinkFromFlashpoint;
use App\Http\Controllers\Controller;
use App\Http\Requests\Flashpoints\LinkRequest;
use App\Models\Flashpoint;
use App\Models\FlashpointIncident;
use App\Models\FlashpointSecurityNote;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Links incidents and community security notes to a flashpoint (Phase 6A).
 * {link} is bound through its scopes (both sides visible) and must belong
 * to {flashpoint}.
 */
class FlashpointLinkController extends Controller
{
    public function storeIncident(LinkRequest $request, Flashpoint $flashpoint, LinkToFlashpoint $link): RedirectResponse
    {
        $incident = $request->incident();
        abort_if($incident === null, 404);
        $this->authorize('linkIncident', [$flashpoint, $incident]);

        $link($flashpoint, $incident, $request->note(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Incident linked.']);
    }

    public function destroyIncident(Request $request, Flashpoint $flashpoint, FlashpointIncident $link, UnlinkFromFlashpoint $unlink): RedirectResponse
    {
        abort_unless($link->flashpoint_id === $flashpoint->id, 404);
        $this->authorize('linkIncident', [$flashpoint, $link->incident]);

        $unlink($flashpoint, $link, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Incident unlinked.']);
    }

    public function storeSecurityNote(LinkRequest $request, Flashpoint $flashpoint, LinkToFlashpoint $link): RedirectResponse
    {
        $note = $request->securityNote();
        abort_if($note === null, 404);
        $this->authorize('linkSecurityNote', [$flashpoint, $note]);

        $link($flashpoint, $note, $request->note(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Security note linked.']);
    }

    public function destroySecurityNote(Request $request, Flashpoint $flashpoint, FlashpointSecurityNote $link, UnlinkFromFlashpoint $unlink): RedirectResponse
    {
        abort_unless($link->flashpoint_id === $flashpoint->id, 404);
        $this->authorize('linkSecurityNote', [$flashpoint, $link->securityNote]);

        $unlink($flashpoint, $link, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Security note unlinked.']);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
