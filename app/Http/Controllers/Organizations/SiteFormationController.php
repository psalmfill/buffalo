<?php

namespace App\Http\Controllers\Organizations;

use App\Enums\SecurityArrangement;
use App\Http\Controllers\Controller;
use App\Models\AgencyFormation;
use App\Models\OrganizationSite;
use App\Models\OrganizationSiteFormation;
use App\Models\User;
use App\Support\Audit\AuditLog;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Government security protecting a site (Phase 4B): links between a site
 * and agency formations. The user must be able to manage the site AND see
 * the formation.
 */
class SiteFormationController extends Controller
{
    public function store(Request $request, OrganizationSite $site): RedirectResponse
    {
        $this->authorize('update', $site);
        /** @var User $user */
        $user = $request->user();

        $data = $request->validate([
            'formation' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($site, $user): void {
                $formation = AgencyFormation::query()->where('ulid', $value)->first();

                if ($formation === null || ! $user->can('view', $formation)) {
                    $fail('Choose a formation you can view.');
                } elseif (OrganizationSiteFormation::query()->where('organization_site_id', $site->id)->where('agency_formation_id', $formation->id)->exists()) {
                    $fail('This formation is already linked to the site.');
                }
            }],
            'arrangement' => ['required', Rule::enum(SecurityArrangement::class)],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        /** @var AgencyFormation $formation */
        $formation = AgencyFormation::query()->where('ulid', $data['formation'])->firstOrFail();
        $link = new OrganizationSiteFormation;
        $link->forceFill([
            'organization_site_id' => $site->id,
            'agency_formation_id' => $formation->id,
            'arrangement' => $data['arrangement'],
            'notes' => $data['notes'] ?? null,
            'created_by' => $user->id,
        ])->save();

        AuditLog::event('site_formation_linked', "Formation {$formation->name} protects site {$site->name}", $site, ['formation' => $formation->ulid, 'arrangement' => $data['arrangement']], $user);

        return back()->with('toast', ['type' => 'success', 'message' => 'Government security added.']);
    }

    public function destroy(Request $request, OrganizationSite $site, OrganizationSiteFormation $link): RedirectResponse
    {
        $this->authorize('update', $site);
        abort_unless($link->organization_site_id === $site->id, 404);

        $link->loadMissing('formation');
        AuditLog::event('site_formation_unlinked', "Formation {$link->formation->name} no longer linked to site {$site->name}", $site, ['formation' => $link->formation->ulid], $request->user());
        $link->delete();

        return back()->with('toast', ['type' => 'success', 'message' => 'Government security removed.']);
    }
}
