<?php

namespace App\Http\Controllers\Organizations;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Actions\Organizations\SaveOrganizationContact;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Http\Requests\Organizations\OrganizationContactRequest;
use App\Models\OrganizationContact;
use App\Models\OrganizationSite;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Contacts at a site (Phase 4B). Shown on the site page (each view logged).
 */
class OrganizationContactController extends Controller
{
    public function store(OrganizationContactRequest $request, OrganizationSite $site, SaveOrganizationContact $save): RedirectResponse
    {
        $this->authorize('update', $site);

        $save->create($site, $request->values(), $this->actor($request));

        return redirect()->route('organization-sites.show', $site)->with('toast', ['type' => 'success', 'message' => 'Contact added.']);
    }

    public function update(OrganizationContactRequest $request, OrganizationContact $contact, SaveOrganizationContact $save): RedirectResponse
    {
        $this->authorize('update', $contact);

        $save->update($contact, $request->values(), $this->actor($request));

        return redirect()->route('organization-sites.show', $contact->site)->with('toast', ['type' => 'success', 'message' => 'Contact saved.']);
    }

    public function destroy(DeleteRecordRequest $request, OrganizationContact $contact, DeleteWithReason $delete): RedirectResponse
    {
        $this->authorize('delete', $contact);

        $site = $contact->site;
        $delete($contact, 'organization_contact', (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('organization-sites.show', $site)->with('toast', ['type' => 'success', 'message' => 'Contact deleted.']);
    }

    private function actor(OrganizationContactRequest|DeleteRecordRequest $request): User
    {
        /** @var User */
        return $request->user();
    }
}
