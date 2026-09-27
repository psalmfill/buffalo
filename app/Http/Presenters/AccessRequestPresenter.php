<?php

namespace App\Http\Presenters;

use App\Enums\AccessRequestStatus;
use App\Enums\Classification;
use App\Enums\GrantScope;
use App\Enums\MatchContext;
use App\Enums\MatchType;
use App\Enums\RequestUrgency;
use App\Models\Person;
use App\Models\RecordAccessGrant;
use App\Models\RecordAccessRequest;
use App\Models\RecordMatchEvent;
use App\Models\User;
use BackedEnum;

/**
 * Access requests, grants, delegations and match events for the browser
 * (Phase 3C): ULIDs and labels, never numeric ids.
 *
 * A request never shows its record unless the viewer can see the person
 * through their own scopes ($record: owners and oversight, or the
 * requester while their grant is active).
 */
final class AccessRequestPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function row(RecordAccessRequest $request, ?Person $record = null): array
    {
        $grant = $request->relationLoaded('grant') ? $request->grant : null;

        return [
            'ulid' => $request->ulid,
            'status' => self::option($request->status),
            'urgency' => self::option($request->urgency),
            'scope' => self::option($request->scope),
            'requested_days' => $request->requested_days,
            'granted_scope' => $request->granted_scope === null ? null : self::option($request->granted_scope),
            'granted_days' => $request->granted_days,
            'owning_state' => $request->owningState->name,
            'requester' => [
                'ulid' => $request->requester->ulid,
                'name' => $request->requester->name,
                'place' => self::place($request),
            ],
            'match_type' => $request->relationLoaded('matchEvent') ? self::option($request->matchEvent->match_type) : null,
            'created_at' => $request->created_at->toIso8601String(),
            'escalated_at' => $request->escalated_at?->toIso8601String(),
            'decided_at' => $request->decided_at?->toIso8601String(),
            'grant' => $grant === null ? null : self::grant($grant),
            'record' => $record === null ? null : [
                'ulid' => $record->ulid,
                'name' => $record->name,
                'classification' => self::option($record->classificationLevel()),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function grant(RecordAccessGrant $grant): array
    {
        return [
            'ulid' => $grant->ulid,
            'scope' => self::option($grant->scope),
            'starts_at' => $grant->starts_at->toIso8601String(),
            'expires_at' => $grant->expires_at->toIso8601String(),
            'revoked_at' => $grant->revoked_at?->toIso8601String(),
            'revocation_reason' => $grant->revocation_reason,
            'active' => $grant->isActive(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function event(RecordMatchEvent $event, ?Person $record = null): array
    {
        return [
            'officer' => ['ulid' => $event->user->ulid, 'name' => $event->user->name, 'place' => $event->user->jurisdictionLabel()],
            'owning_state' => $event->owningState->name,
            'match_type' => self::option($event->match_type),
            'context' => self::option($event->context),
            'suppressed' => $event->suppressed,
            'created_at' => $event->created_at->toIso8601String(),
            'record' => $record === null ? null : ['ulid' => $record->ulid, 'name' => $record->name],
        ];
    }

    /**
     * The requester as a decider sees them.
     *
     * @return array<string, mixed>
     */
    public static function requester(User $user): array
    {
        return [
            'ulid' => $user->ulid,
            'name' => $user->name,
            'rank' => $user->rank,
            'role' => $user->getRole()?->label(),
            'jurisdiction' => $user->jurisdictionLabel(),
        ];
    }

    /**
     * @return array<string, list<array{value: string, label: string, description?: string}>>
     */
    public static function options(): array
    {
        return [
            'scopes' => array_map(fn (GrantScope $s) => [...self::option($s), 'description' => $s->description()], GrantScope::cases()),
            'urgencies' => array_map(self::option(...), RequestUrgency::cases()),
            'days' => array_map(fn (int $d) => ['value' => (string) $d, 'label' => "{$d} days"], [7, 14, 30]),
        ];
    }

    private static function place(RecordAccessRequest $request): string
    {
        return $request->requesterLga !== null
            ? "{$request->requesterLga->name} LGA, {$request->requesterState?->name} State"
            : ($request->requesterState !== null ? "{$request->requesterState->name} State" : 'National');
    }

    /**
     * @return array{value: string, label: string}
     */
    public static function option(BackedEnum $case): array
    {
        /** @var AccessRequestStatus|GrantScope|RequestUrgency|MatchType|MatchContext|Classification $case */
        return ['value' => (string) $case->value, 'label' => $case->label()];
    }
}
