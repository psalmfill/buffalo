<?php

namespace App\Http\Controllers\Threats;

use App\Actions\Threats\LinkToThreatReport;
use App\Actions\Threats\UnlinkFromThreatReport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threats\ThreatLinkRequest;
use App\Models\CriminalGroup;
use App\Models\Flashpoint;
use App\Models\ThreatReport;
use App\Models\ThreatReportCriminalGroup;
use App\Models\ThreatReportFlashpoint;
use App\Models\ThreatReportIncident;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Links flashpoints, criminal groups and incidents to a threat report
 * (Phase 6B). {kind} is flashpoints, groups or incidents; {link} is looked
 * up through its scopes (both sides visible) and must belong to {threat}.
 */
class ThreatLinkController extends Controller
{
    public function store(ThreatLinkRequest $request, ThreatReport $threat, string $kind, LinkToThreatReport $link): RedirectResponse
    {
        $record = $request->record();
        abort_if($record === null, 404);
        $this->authorize(match (true) {
            $record instanceof Flashpoint => 'linkFlashpoint',
            $record instanceof CriminalGroup => 'linkGroup',
            default => 'linkIncident',
        }, [$threat, $record]);

        $link($threat, $record, $request->linkKind(), $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Linked.']);
    }

    public function destroy(Request $request, ThreatReport $threat, string $kind, string $link, UnlinkFromThreatReport $unlink): RedirectResponse
    {
        $row = match ($kind) {
            'flashpoints' => ThreatReportFlashpoint::query()->with('flashpoint')->where('ulid', $link)->first(),
            'groups' => ThreatReportCriminalGroup::query()->with('criminalGroup')->where('ulid', $link)->first(),
            'incidents' => ThreatReportIncident::query()->with('incident')->where('ulid', $link)->first(),
            default => null,
        };
        abort_if($row === null || $row->threat_report_id !== $threat->id, 404);

        $this->authorize(...match (true) {
            $row instanceof ThreatReportFlashpoint => ['linkFlashpoint', [$threat, $row->flashpoint]],
            $row instanceof ThreatReportCriminalGroup => ['linkGroup', [$threat, $row->criminalGroup]],
            default => ['linkIncident', [$threat, $row->incident]],
        });

        $unlink($threat, $row, $this->actor($request));

        return back()->with('toast', ['type' => 'success', 'message' => 'Unlinked.']);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
