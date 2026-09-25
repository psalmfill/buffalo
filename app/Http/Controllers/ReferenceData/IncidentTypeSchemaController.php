<?php

namespace App\Http\Controllers\ReferenceData;

use App\Actions\ReferenceData\UpdateIncidentTypeSchema;
use App\Http\Controllers\Controller;
use App\Http\Requests\ReferenceData\IncidentTypeSchemaRequest;
use App\Models\IncidentType;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

/**
 * Saves an incident type's detail fields (the field builder).
 */
class IncidentTypeSchemaController extends Controller
{
    public function update(IncidentTypeSchemaRequest $request, IncidentType $incidentType, UpdateIncidentTypeSchema $update): RedirectResponse
    {
        $this->authorize('update', $incidentType);

        /** @var User $actor */
        $actor = $request->user();

        $changed = $update($incidentType, $request->schema(), $actor);

        return back()->with('toast', [
            'type' => $changed ? 'success' : 'info',
            'message' => $changed
                ? "Detail fields saved (version {$incidentType->schema_version})."
                : 'No changes to save.',
        ]);
    }
}
