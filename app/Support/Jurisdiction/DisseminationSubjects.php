<?php

namespace App\Support\Jurisdiction;

use App\Models\RecordDissemination;
use App\Support\Jurisdiction\Contracts\Disseminable;
use Illuminate\Database\Eloquent\Model;

/**
 * The record a dissemination is about, read by id outside the viewer's scopes
 * (Phase 7A).
 *
 * SANCTIONED SCOPE BYPASS (inside App\Support\Jurisdiction). Policies and
 * notifiers evaluate an EXPLICIT user (who may not be the current one, e.g.
 * a notification recipient), so the record must not be filtered by the
 * current viewer's scopes. Callers MUST authorise the returned record for
 * the user concerned (JurisdictionGate / ClassificationGate / `view`)
 * before showing anything from it.
 */
final class DisseminationSubjects
{
    public static function find(RecordDissemination $dissemination): (Model&Disseminable)|null
    {
        return self::byId($dissemination->subject_type, $dissemination->subject_id);
    }

    public static function byId(string $type, int $id): (Model&Disseminable)|null
    {
        $class = RecordDissemination::subjectClass($type);
        $context = app(JurisdictionContext::class);
        [$mode, $user] = [$context->mode(), $context->currentUser()];

        // The system context lifts both the jurisdiction and the
        // classification scopes for this one read. Not runAsSystem(): this
        // runs inside every policy check (an audit entry each time), and the
        // record is never shown without authorising it for the user
        // concerned. The previous context is always restored.
        $context->system();

        try {
            /** @var (Model&Disseminable)|null */
            return $class::query()->whereKey($id)->first();
        } finally {
            match (true) {
                $mode === ContextMode::User && $user !== null => $context->user($user),
                $mode === ContextMode::System => $context->system(),
                default => $context->none(),
            };
        }
    }
}
