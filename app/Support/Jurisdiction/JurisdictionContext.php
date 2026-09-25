<?php

namespace App\Support\Jurisdiction;

use App\Models\User;
use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * Whose jurisdiction queries are currently scoped to. Bound as a scoped
 * singleton: a fresh instance per HTTP request and per queued job.
 *
 * Defaults (see AppServiceProvider): `none` in HTTP requests until
 * SetJurisdictionContext runs; `system` in the console and queue workers.
 * A missing user never means "see everything" (CLAUDE.md 4.6).
 */
final class JurisdictionContext
{
    private ?User $user = null;

    public function __construct(private ContextMode $mode) {}

    public function user(User $user): void
    {
        $this->mode = ContextMode::User;
        $this->user = $user;
    }

    public function system(): void
    {
        $this->mode = ContextMode::System;
        $this->user = null;
    }

    public function none(): void
    {
        $this->mode = ContextMode::None;
        $this->user = null;
    }

    public function mode(): ContextMode
    {
        return $this->mode;
    }

    public function isSystem(): bool
    {
        return $this->mode === ContextMode::System;
    }

    public function isUser(): bool
    {
        return $this->mode === ContextMode::User;
    }

    /**
     * The user whose jurisdiction applies, in `user` mode only.
     */
    public function currentUser(): ?User
    {
        return $this->mode === ContextMode::User ? $this->user : null;
    }

    /**
     * Runs the callback scoped to the given user's jurisdiction, then
     * restores the previous mode (also when the callback throws). Queued
     * jobs acting on behalf of a user must use this.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function actingAs(User $user, Closure $callback): mixed
    {
        [$mode, $previousUser] = [$this->mode, $this->user];

        $this->user($user);

        try {
            return $callback();
        } finally {
            [$this->mode, $this->user] = [$mode, $previousUser];
        }
    }

    /**
     * The ONLY sanctioned way to lift jurisdiction scoping inside a request.
     * Every call is recorded in the activity log (reason + acting user)
     * before the callback runs, so it is logged even if the callback fails.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function runAsSystem(string $reason, Closure $callback): mixed
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('runAsSystem() requires a reason.');
        }

        [$mode, $previousUser] = [$this->mode, $this->user];

        $logged = activity('jurisdiction')
            ->causedBy($previousUser)
            ->event('system_bypass')
            ->withProperties([
                'reason' => $reason,
                'previous_mode' => $mode->value,
            ])
            ->log("Jurisdiction scope bypassed: {$reason}");

        if ($logged === null) {
            throw new RuntimeException('Activity logging is disabled; refusing to bypass jurisdiction scoping without an audit record.');
        }

        $this->system();

        try {
            return $callback();
        } finally {
            [$this->mode, $this->user] = [$mode, $previousUser];
        }
    }
}
