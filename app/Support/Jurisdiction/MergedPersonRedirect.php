<?php

namespace App\Support\Jurisdiction;

use App\Models\Person;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * A merged person's URL (Phase 10A.7): redirects to the final survivor
 * (following chains of merges) only when the viewer can see that survivor;
 * otherwise 404, exactly like any record outside the viewer's reach.
 *
 * Reading the merge chain needs the soft-deleted source by ULID whoever the
 * viewer is (the bypass is limited to persons.merged_into_person_id here,
 * in App\Support\Jurisdiction); the survivor is then loaded through the
 * viewer's own scopes and policy.
 */
final class MergedPersonRedirect
{
    private const MAX_CHAIN = 20;

    public static function respond(Request $request): RedirectResponse
    {
        $ulid = $request->route('person');
        $user = $request->user();

        if (! is_string($ulid) || ! $user instanceof User) {
            throw new NotFoundHttpException;
        }

        $id = DB::table('persons')->where('ulid', strtolower($ulid))->whereNotNull('merged_into_person_id')->value('merged_into_person_id');

        for ($step = 0; $id !== null && $step < self::MAX_CHAIN; $step++) {
            $next = DB::table('persons')->where('id', $id)->whereNotNull('merged_into_person_id')->value('merged_into_person_id');

            if ($next === null) {
                break;
            }

            $id = $next;
        }

        $survivor = $id === null ? null : Person::query()->whereKey($id)->first();

        if (! $survivor instanceof Person || ! $user->can('view', $survivor)) {
            throw new NotFoundHttpException;
        }

        return redirect()->route('persons.show', $survivor)
            ->with('toast', ['type' => 'info', 'message' => 'That record was merged into this one.']);
    }
}
