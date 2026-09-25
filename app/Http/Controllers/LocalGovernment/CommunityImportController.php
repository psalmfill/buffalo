<?php

namespace App\Http\Controllers\LocalGovernment;

use App\Actions\LocalGovernment\ClassifyCommunityRows;
use App\Actions\LocalGovernment\ImportCommunities;
use App\Http\Controllers\Controller;
use App\Http\Requests\LocalGovernment\CommunityImportPreviewRequest;
use App\Http\Requests\LocalGovernment\CommunityImportRequest;
use App\Jobs\ImportCommunitiesJob;
use App\Models\Lga;
use App\Models\User;
use App\Support\Jurisdiction\JurisdictionOptions;
use App\Support\LocalGovernment\CommunityCsv;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Bulk community onboarding from CSV: upload, preview (every row
 * classified new / duplicate / error), confirm. The file is read from PHP's
 * temporary upload and never stored; confirmation re-sends the previewed
 * rows, which are validated and classified again.
 */
class CommunityImportController extends Controller
{
    public function create(Request $request, JurisdictionOptions $options): Response
    {
        $this->authorize('importAnyCommunities', Lga::class);

        return Inertia::render('communities/import', [
            'lgas' => $options->lgaQuery($this->actor($request))->active()->with('state')->get()
                ->sortBy(fn (Lga $lga) => $lga->state->name.' '.$lga->name)
                ->map(fn (Lga $lga) => ['ulid' => $lga->ulid, 'name' => $lga->name, 'state' => $lga->state->name])
                ->values(),
            'selected' => $request->query('lga', ''),
            'columns' => CommunityCsv::HEADER,
            'threshold' => ImportCommunitiesJob::THRESHOLD,
        ]);
    }

    public function template(): HttpResponse
    {
        $this->authorize('importAnyCommunities', Lga::class);

        return response(CommunityCsv::template(), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="communities-template.csv"',
        ]);
    }

    public function preview(CommunityImportPreviewRequest $request, JurisdictionOptions $options, ClassifyCommunityRows $classify): Response
    {
        $lga = $this->lga($request, $options);

        /** @var UploadedFile $file */
        $file = $request->file('file');
        $rows = $classify($lga, CommunityCsv::read((string) $file->getRealPath()));

        $lga->loadMissing('state');

        return Inertia::render('communities/import-preview', [
            'lga' => ['ulid' => $lga->ulid, 'name' => $lga->name, 'state' => $lga->state->name],
            'rows' => $rows,
            'summary' => [
                'new' => count(array_filter($rows, fn (array $row) => $row['status'] === 'new')),
                'duplicate' => count(array_filter($rows, fn (array $row) => $row['status'] === 'duplicate')),
                'error' => count(array_filter($rows, fn (array $row) => $row['status'] === 'error')),
            ],
            'threshold' => ImportCommunitiesJob::THRESHOLD,
        ]);
    }

    /** A reload of the preview URL: nothing is kept, so start again. */
    public function restart(): RedirectResponse
    {
        return redirect()->route('communities.import.create');
    }

    public function store(CommunityImportRequest $request, JurisdictionOptions $options, ImportCommunities $import): RedirectResponse
    {
        $lga = $this->lga($request, $options);
        $user = $this->actor($request);
        $rows = $request->rows();
        $include = $request->includedLines();
        $destination = redirect()->route('lgas.show', ['lga' => $lga, 'tab' => 'communities']);

        if (count($rows) > ImportCommunitiesJob::THRESHOLD) {
            ImportCommunitiesJob::dispatch($user->id, $lga->id, $rows, $include);

            return $destination->with('toast', ['type' => 'info', 'message' => 'The import is running in the background. You will be notified when it finishes.']);
        }

        $result = $import($lga, $rows, $include, $user);

        return $destination->with('toast', [
            'type' => 'success',
            'message' => "{$result['imported']} communities imported ({$result['skipped']} skipped, {$result['errors']} with errors).",
        ]);
    }

    /**
     * The chosen LGA, if the user may import into it.
     */
    private function lga(Request $request, JurisdictionOptions $options): Lga
    {
        $user = $this->actor($request);
        $lga = $options->lgaQuery($user)->where('lgas.ulid', (string) $request->input('lga'))->first();

        if ($lga === null) {
            throw ValidationException::withMessages(['lga' => 'Choose an LGA within your jurisdiction.']);
        }

        $this->authorize('importCommunities', $lga);

        return $lga;
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
