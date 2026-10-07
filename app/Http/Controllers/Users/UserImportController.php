<?php

namespace App\Http\Controllers\Users;

use App\Actions\Users\ClassifyUserImportRows;
use App\Actions\Users\ImportUsers;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Users\UserImportPreviewRequest;
use App\Http\Requests\Users\UserImportRequest;
use App\Models\GeopoliticalZone;
use App\Models\User;
use App\Support\Auth\StepUp;
use App\Support\Users\UserCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Bulk user import (Phase 9B.2), super admins: upload a CSV, preview every
 * row as new / exists / error, confirm (step-up) to create the accounts in
 * one transaction. The file is malware-scanned, read from PHP's temporary
 * upload and never stored; confirmation re-sends the previewed rows, which
 * are validated again. No setup links are issued.
 */
class UserImportController extends Controller
{
    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('users/import', [
            'columns' => UserCsv::HEADER,
            'maxRows' => UserCsv::MAX_ROWS,
            'roles' => array_map(fn (Role $role) => ['value' => $role->value, 'label' => $role->label()], ClassifyUserImportRows::importableRoles()),
            'zones' => GeopoliticalZone::query()->orderBy('name')->get()->map(fn (GeopoliticalZone $zone) => ['code' => $zone->code, 'name' => $zone->name])->values(),
        ]);
    }

    public function template(): HttpResponse
    {
        $this->authorize('create', User::class);

        return response(UserCsv::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="users-template.csv"',
        ]);
    }

    public function preview(UserImportPreviewRequest $request, ClassifyUserImportRows $classify): Response
    {
        /** @var UploadedFile $file */
        $file = $request->file('file');
        $rows = $classify(UserCsv::read((string) $file->getRealPath()));

        return Inertia::render('users/import-preview', [
            'rows' => $rows,
            'summary' => [
                'new' => count(array_filter($rows, fn (array $row) => $row['status'] === 'new')),
                'exists' => count(array_filter($rows, fn (array $row) => $row['status'] === 'exists')),
                'error' => count(array_filter($rows, fn (array $row) => $row['status'] === 'error')),
            ],
        ]);
    }

    /** A reload of the preview URL: nothing is kept, so start again. */
    public function restart(): RedirectResponse
    {
        $this->authorize('create', User::class);

        return redirect()->route('users.import.create');
    }

    public function store(UserImportRequest $request, ImportUsers $import): RedirectResponse
    {
        // Creating accounts with roles and jurisdictions (Phase 8B.2).
        StepUp::ensure($request);

        $actor = $request->user();
        abort_unless($actor instanceof User, 403);

        $result = $import($request->rows(), $actor);

        return redirect()->route('users.onboarding', ['status' => 'setup_pending'])->with('toast', [
            'type' => 'success',
            'message' => "{$result['created']} accounts created ({$result['skipped']} already existed). Issue their setup links from this page.",
        ]);
    }
}
