<?php

namespace App\Support\Auth;

use App\Enums\LoginEvent;
use App\Models\LoginHistory;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Records authentication events in the append-only login history.
 */
final class LoginHistoryRecorder
{
    public function __construct(private readonly Request $request) {}

    public function record(LoginEvent $event, ?User $user = null, ?string $emailAttempted = null): LoginHistory
    {
        $email = $emailAttempted ?? $user?->email;

        return LoginHistory::query()->create([
            'user_id' => $user?->id,
            'email_attempted' => $email === null ? null : Str::limit(Str::lower(trim($email)), 255, ''),
            'event' => $event,
            'ip_address' => $this->request->ip(),
            'user_agent' => Str::limit((string) $this->request->userAgent(), 1000, ''),
        ]);
    }
}
