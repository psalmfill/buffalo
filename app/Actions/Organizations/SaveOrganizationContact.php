<?php

namespace App\Actions\Organizations;

use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a contact at a site (Phase 4B). The contact's
 * state_id/lga_id are copied from the site (access rule 9). Person links
 * are a named event with ULIDs only.
 */
final class SaveOrganizationContact
{
    /**
     * @param  array<string, mixed>  $data  OrganizationContactRequest::values()
     */
    public function create(OrganizationSite $site, array $data, User $actor): OrganizationContact
    {
        return DB::transaction(function () use ($site, $data, $actor): OrganizationContact {
            $contact = new OrganizationContact;
            $contact->forceFill([
                ...$data,
                'organization_site_id' => $site->id,
                'state_id' => $site->state_id,
                'lga_id' => $site->lga_id,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ])->save();

            $this->logPerson($contact, null, $actor);

            return $contact;
        });
    }

    /**
     * @param  array<string, mixed>  $data  OrganizationContactRequest::values()
     */
    public function update(OrganizationContact $contact, array $data, User $actor): void
    {
        DB::transaction(function () use ($contact, $data, $actor): void {
            $before = $contact->person_id;
            $contact->forceFill($data);

            if ($contact->isDirty()) {
                $contact->forceFill(['updated_by' => $actor->id])->save();
            }

            $this->logPerson($contact, $before, $actor);
        });
    }

    private function logPerson(OrganizationContact $contact, ?int $before, User $actor): void
    {
        if ($before === $contact->person_id) {
            return;
        }

        // ULIDs only: the contact's history may be read by users who cannot see the person.
        $ulid = fn (?int $id) => $id === null ? null : DB::table('persons')->where('id', $id)->value('ulid');

        AuditLog::event('person_link_changed', "Person link of contact {$contact->name} changed", $contact, [
            'old' => $ulid($before),
            'new' => $ulid($contact->person_id),
        ], $actor);
    }
}
