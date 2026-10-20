<?php

namespace App\Http\Controllers\Arrests;

use App\Actions\Arrests\ChangeArrestStatus;
use App\Enums\ArrestStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Arrests\ArrestStatusRequest;
use App\Models\Arrest;
use App\Models\ArrestPerson;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Status changes of an arrest or one arrested person (Phase 11C).
 */
class ArrestStatusController extends Controller
{
    public function store(ArrestStatusRequest $request, Arrest $arrest, ChangeArrestStatus $change): RedirectResponse
    {
        $this->authorize('update', $arrest);
        /** @var User $user */
        $user = $request->user();

        $ulid = $request->validated('person');
        $person = is_string($ulid)
            ? ArrestPerson::query()->where('arrest_id', $arrest->id)->where('ulid', $ulid)->first()
            : null;
        abort_if(is_string($ulid) && $person === null, 404);

        $change($arrest, $person, ArrestStatus::from((string) $request->validated('status')), trim((string) $request->validated('comment')), $user);

        return back()->with('toast', ['type' => 'success', 'message' => 'Status changed.']);
    }
}
