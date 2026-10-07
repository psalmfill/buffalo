<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\GeopoliticalZone;
use App\Models\Lga;
use App\Models\State;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates every row of a bulk user import in full before anything is
 * written (Phase 9B.2) and classifies it:
 *
 * - new:    valid; the account will be created
 * - exists: an account with this email already exists (skipped)
 * - error:  invalid (unknown role or place, wrong role/jurisdiction
 *           combination, duplicate in the file, service number taken, ...)
 *
 * Role and jurisdiction rules are those of the single-user form
 * (UserJurisdiction). Super admin accounts are never created in bulk.
 */
final class ClassifyUserImportRows
{
    /**
     * @param  list<array<string, string|int>>  $rows  as read by UserCsv (or re-sent from the preview)
     * @return list<array{line: int, status: string, errors: list<string>, name: string, email: string, phone: string, service_number: string, rank: string, role: string, role_label: string, state_code: string, lga_code: string, zone_codes: string, place: string}>
     */
    public function __invoke(array $rows): array
    {
        $states = State::query()->get()->keyBy('code');
        $zones = GeopoliticalZone::query()->get()->keyBy('code');
        $emails = array_values(array_filter(array_map(fn (array $row): string => Str::lower(trim((string) ($row['email'] ?? ''))), $rows)));
        $serviceNumbers = array_values(array_filter(array_map(fn (array $row): string => trim((string) ($row['service_number'] ?? '')), $rows)));

        // Including deactivated and deleted accounts: both are unique.
        $existingEmails = array_flip(DB::table('users')->whereIn(DB::raw('lower(email)'), $emails)->pluck('email')->map(fn ($e) => Str::lower((string) $e))->all());
        $takenServiceNumbers = array_flip(DB::table('users')->whereIn('service_number', $serviceNumbers)->pluck('service_number')->map(fn ($n) => (string) $n)->all());

        $seenEmails = [];
        $seenServiceNumbers = [];
        $result = [];

        foreach ($rows as $row) {
            $field = fn (string $key): string => trim((string) ($row[$key] ?? ''));
            $email = Str::lower($field('email'));
            $errors = [];

            $base = Validator::make(
                ['name' => $field('name'), 'email' => $email, 'phone' => $field('phone'), 'service_number' => $field('service_number'), 'rank' => $field('rank')],
                [
                    'name' => ['required', 'string', 'max:255'],
                    'email' => ['required', 'string', 'email', 'max:255'],
                    'phone' => ['nullable', 'string', 'max:50'],
                    'service_number' => ['nullable', 'string', 'max:100'],
                    'rank' => ['nullable', 'string', 'max:100'],
                ],
            );
            foreach ($base->errors()->all() as $message) {
                $errors[] = $message;
            }

            $role = Role::tryFrom($field('role'));
            $state = $field('state_code') === '' ? null : $states->get(strtoupper($field('state_code')));
            $lga = null;
            $zoneModels = [];

            if ($role === null) {
                $errors[] = sprintf('Unknown role "%s". Use one of: %s.', $field('role'), implode(', ', array_map(fn (Role $r) => $r->value, self::importableRoles())));
            } elseif ($role === Role::SuperAdmin) {
                $errors[] = 'Super admin accounts cannot be imported; create them one at a time.';
            }

            if ($field('state_code') !== '' && $state === null) {
                $errors[] = sprintf('Unknown state code "%s".', $field('state_code'));
            }

            if ($field('lga_code') !== '') {
                if ($state === null) {
                    $errors[] = 'An LGA code needs the state_code of its state.';
                } else {
                    $lga = Lga::query()->where('state_id', $state->id)->where('code', strtoupper($field('lga_code')))->first();
                    if ($lga === null) {
                        $errors[] = sprintf('Unknown LGA code "%s" in %s.', $field('lga_code'), $state->name);
                    }
                }
            }

            foreach (array_filter(array_map('trim', explode(';', $field('zone_codes')))) as $code) {
                $zone = $zones->get(strtoupper($code));
                $zone === null ? $errors[] = sprintf('Unknown zone code "%s".', $code) : $zoneModels[] = $zone;
            }

            if ($role !== null && $role !== Role::SuperAdmin && $errors === []) {
                try {
                    (new UserJurisdiction($role, $state, $lga, $zoneModels))->validate();
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $messages) {
                        array_push($errors, ...$messages);
                    }
                }
            }

            if ($email !== '' && isset($seenEmails[$email])) {
                $errors[] = "The same email appears on line {$seenEmails[$email]}.";
            }
            $serviceNumber = $field('service_number');
            if ($serviceNumber !== '' && isset($seenServiceNumbers[$serviceNumber])) {
                $errors[] = "The same service number appears on line {$seenServiceNumbers[$serviceNumber]}.";
            }
            $exists = $email !== '' && isset($existingEmails[$email]);
            if (! $exists && $serviceNumber !== '' && isset($takenServiceNumbers[$serviceNumber])) {
                $errors[] = 'This service number belongs to an existing account.';
            }

            $line = (int) ($row['line'] ?? 0);
            $seenEmails[$email] ??= $line;
            if ($serviceNumber !== '') {
                $seenServiceNumbers[$serviceNumber] ??= $line;
            }

            $result[] = [
                'line' => $line,
                'status' => $errors !== [] ? 'error' : ($exists ? 'exists' : 'new'),
                'errors' => array_values(array_unique($errors)),
                'name' => $field('name'),
                'email' => $email,
                'phone' => $field('phone'),
                'service_number' => $serviceNumber,
                'rank' => $field('rank'),
                'role' => $field('role'),
                'role_label' => $role?->label() ?? $field('role'),
                'state_code' => strtoupper($field('state_code')),
                'lga_code' => strtoupper($field('lga_code')),
                'zone_codes' => $field('zone_codes'),
                'place' => match (true) {
                    $lga !== null => "{$lga->name}, {$state?->name}",
                    $state !== null => (string) $state->name,
                    $zoneModels !== [] => implode(', ', array_map(fn (GeopoliticalZone $z) => $z->name, $zoneModels)),
                    default => $role === null ? '' : 'National',
                },
            ];
        }

        return $result;
    }

    /**
     * @return list<Role>
     */
    public static function importableRoles(): array
    {
        return array_values(array_filter(Role::cases(), fn (Role $role) => $role !== Role::SuperAdmin));
    }
}
