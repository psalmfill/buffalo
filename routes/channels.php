<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Each user's private notification channel, identified by ULID (numeric ids
// never reach the browser). Only the owner, and only an unblocked account.
Broadcast::channel('users.{ulid}', fn (User $user, string $ulid): bool => $user->ulid === $ulid && ! $user->isAccessBlocked());
