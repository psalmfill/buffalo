<?php

use App\Enums\JurisdictionLevel;
use App\Enums\Permission;
use App\Models\State;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use Illuminate\Support\Facades\Broadcast;

// Each user's private notification channel, identified by ULID (numeric ids
// never reach the browser). Only the owner, and only an unblocked account.
Broadcast::channel('users.{ulid}', fn (User $user, string $ulid): bool => $user->ulid === $ulid && ! $user->isAccessBlocked());

// Incident events (Phase 5C). Payloads carry only the incident ULID and the
// event name; clients refetch through scoped routes. A state's channel is
// for users whose jurisdiction covers any part of it (LGA chiefs of the
// state included); national users listen on `national.incidents`.
Broadcast::channel('states.{stateUlid}.incidents', function (User $user, string $stateUlid): bool {
    if ($user->isAccessBlocked() || ! $user->checkPermissionTo(Permission::IncidentsView->value)) {
        return false;
    }

    $state = State::query()->where('ulid', $stateUlid)->first();

    return $state !== null && app(JurisdictionOptions::class)->coversState($user, $state);
});

Broadcast::channel('national.incidents', fn (User $user): bool => ! $user->isAccessBlocked()
    && $user->jurisdiction_level === JurisdictionLevel::National
    && $user->checkPermissionTo(Permission::IncidentsView->value));
