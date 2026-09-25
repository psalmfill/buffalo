<?php

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a user with one role and a valid jurisdiction, in a transaction.
 *
 * The account gets a long random password that nobody knows: the user sets
 * their own password through the account-setup flow (Phase 1C/1D). Accounts
 * are only ever created by administrators, so the email address is treated
 * as verified.
 *
 * Authorisation (UserPolicy::create) is the caller's job.
 */
final class CreateUser
{
    public function __construct(private readonly AssignRole $assignRole) {}

    /**
     * @param  array{name: string, email: string, phone?: string|null, service_number?: string|null, rank?: string|null}  $data
     *
     * @throws ValidationException
     */
    public function __invoke(array $data, UserJurisdiction $jurisdiction, ?User $actor = null): User
    {
        $data['email'] = Str::lower(trim($data['email']));

        $data = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'service_number' => ['nullable', 'string', 'max:100', 'unique:users,service_number'],
            'rank' => ['nullable', 'string', 'max:100'],
        ])->validate();

        $jurisdiction->validate();

        return DB::transaction(function () use ($data, $jurisdiction, $actor): User {
            $user = new User([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'service_number' => $data['service_number'] ?? null,
                'rank' => $data['rank'] ?? null,
                'password' => Str::password(64),
            ]);

            $user->forceFill([
                ...$jurisdiction->attributes(),
                'is_active' => true,
                'email_verified_at' => now(),
                'created_by' => $actor?->id,
                'updated_by' => $actor?->id,
            ])->save();

            ($this->assignRole)($user, $jurisdiction->role);
            $user->zones()->sync($jurisdiction->zoneIds());

            return $user->load('roles', 'zones');
        });
    }
}
