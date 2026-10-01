<?php

namespace App\Actions\Threats;

use App\Enums\ThreatCredibility;
use App\Enums\ThreatUrgency;
use App\Events\Threats\ThreatReportChanged;
use App\Models\Lga;
use App\Models\ThreatReport;
use App\Models\User;
use App\Support\Audit\AuditLog;
use App\Support\Threats\ThreatNotifier;
use Illuminate\Support\Facades\DB;

/**
 * Edits a threat report (Phase 6B). A change of credibility or urgency is
 * a named event with the officer's comment; raising urgency to immediate,
 * or credibility to confirmed, alerts the immediate set (critical). The
 * area is logged by coordinates. Status and expiry have their own actions.
 */
final class UpdateThreatReport
{
    public function __construct(private readonly ThreatNotifier $notifier) {}

    /**
     * @param  array<string, mixed>  $data  ThreatReportRequest::values()
     */
    public function __invoke(ThreatReport $threat, ?Lga $lga, array $data, ?string $comment, User $actor): void
    {
        $escalated = DB::transaction(function () use ($threat, $lga, $data, $comment, $actor): bool {
            $locked = ThreatReport::query()->whereKey($threat->id)->lockForUpdate()->firstOrFail();
            $areaBefore = ThreatReportAttributes::area($locked);
            $attributes = ThreatReportAttributes::from($data);
            $areaAfter = $attributes['location'] === null ? null : [
                'latitude' => round((float) $data['latitude'], 6),
                'longitude' => round((float) $data['longitude'], 6),
                'radius_meters' => $data['radius_meters'],
            ];

            if ($areaBefore !== null && $areaAfter !== null && $areaBefore['latitude'] === $areaAfter['latitude'] && $areaBefore['longitude'] === $areaAfter['longitude']) {
                unset($attributes['location']);
            }

            if ($lga !== null && $lga->id !== $locked->lga_id) {
                $attributes['lga_id'] = $lga->id;
                $attributes['state_id'] = $lga->state_id;
            }

            $credibility = ThreatCredibility::from((string) $data['credibility']);
            $urgency = ThreatUrgency::from((string) $data['urgency']);
            $before = ['credibility' => $locked->credibility, 'urgency' => $locked->urgency];

            $locked->forceFill([...$attributes, 'credibility' => $credibility, 'urgency' => $urgency]);

            if ($locked->isDirty()) {
                $locked->forceFill(['updated_by' => $actor->id])->save();
            }

            if ($before['credibility'] !== $credibility) {
                AuditLog::event('credibility_changed', "Credibility changed from {$before['credibility']->label()} to {$credibility->label()}", $locked, ['old' => $before['credibility']->value, 'new' => $credibility->value, 'comment' => $comment], $actor);
            }

            if ($before['urgency'] !== $urgency) {
                AuditLog::event('urgency_changed', "Urgency changed from {$before['urgency']->label()} to {$urgency->label()}", $locked, ['old' => $before['urgency']->value, 'new' => $urgency->value, 'comment' => $comment], $actor);
            }

            if ($areaBefore !== $areaAfter) {
                AuditLog::event('area_changed', 'Threat area changed', $locked, ['old' => $areaBefore, 'new' => $areaAfter], $actor);
            }

            ThreatReportChanged::dispatch($locked);
            $threat->setRawAttributes($locked->getAttributes(), true);

            return ($before['urgency'] !== ThreatUrgency::Immediate && $urgency === ThreatUrgency::Immediate)
                || ($before['credibility'] !== ThreatCredibility::Confirmed && $credibility === ThreatCredibility::Confirmed);
        });

        if ($escalated) {
            $this->notifier->sendImmediate($threat->fresh() ?? $threat, 'escalated', [$actor->id]);
        }
    }
}
