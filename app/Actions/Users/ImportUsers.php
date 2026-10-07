<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\GeopoliticalZone;
use App\Models\Lga;
use App\Models\State;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates the accounts of a confirmed bulk import (Phase 9B.2) in ONE
 * transaction: the rows are classified again and the import is refused
 * while any row has an error. Existing accounts are skipped. No setup link
 * is issued: links are handed out later, by email or in a handover session.
 */
final class ImportUsers
{
    public function __construct(
        private readonly ClassifyUserImportRows $classify,
        private readonly CreateUser $createUser,
    ) {}

    /**
     * @param  list<array<string, string|int>>  $rows
     * @return array{created: int, skipped: int}
     *
     * @throws ValidationException
     */
    public function __invoke(array $rows, User $actor): array
    {
        $classified = ($this->classify)($rows);
        $errors = array_filter($classified, fn (array $row) => $row['status'] === 'error');

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'rows' => sprintf('%d row(s) have errors (first: line %d). Correct the file and upload it again.', count($errors), reset($errors)['line']),
            ]);
        }

        return DB::transaction(function () use ($classified, $actor): array {
            $created = 0;

            foreach ($classified as $row) {
                if ($row['status'] !== 'new') {
                    continue;
                }

                $state = $row['state_code'] === '' ? null : State::query()->where('code', $row['state_code'])->firstOrFail();
                $lga = $row['lga_code'] === '' || $state === null ? null : Lga::query()->where('state_id', $state->id)->where('code', $row['lga_code'])->firstOrFail();
                $zones = array_values(GeopoliticalZone::query()
                    ->whereIn('code', array_map('strtoupper', array_filter(array_map('trim', explode(';', $row['zone_codes'])))))
                    ->get()->all());

                ($this->createUser)(
                    [
                        'name' => $row['name'],
                        'email' => $row['email'],
                        'phone' => $row['phone'] === '' ? null : $row['phone'],
                        'service_number' => $row['service_number'] === '' ? null : $row['service_number'],
                        'rank' => $row['rank'] === '' ? null : $row['rank'],
                    ],
                    new UserJurisdiction(Role::from($row['role']), $state, $lga, $zones),
                    $actor,
                );
                $created++;
            }

            $skipped = count($classified) - $created;

            // Counts only; each account's creation is in the activity log.
            AuditLog::event('users_imported', "{$created} user accounts imported", null, ['created' => $created, 'skipped_existing' => $skipped], $actor);

            return ['created' => $created, 'skipped' => $skipped];
        });
    }
}
