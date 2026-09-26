<?php

namespace App\Http\Controllers\CommunityInformation;

use App\Actions\CommunityInformation\DeleteWithReason;
use App\Actions\CommunityInformation\SaveVulnerableFigures;
use App\Http\Controllers\Controller;
use App\Http\Presenters\CommunityInformationPresenter;
use App\Http\Presenters\LgaPresenter;
use App\Http\Requests\CommunityInformation\VulnerableFiguresRequest;
use App\Http\Requests\LocalGovernment\DeleteRecordRequest;
use App\Models\Lga;
use App\Models\User;
use App\Models\VulnerablePopulationStat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Vulnerable population figures (aggregate counts only): add figures,
 * correct a figure, delete one (with a reason). Shown on the LGA and
 * community pages (TabData).
 */
class VulnerableFiguresController extends Controller
{
    use FormOptions;

    public function create(Request $request, Lga $lga): Response
    {
        $this->authorize('addVulnerableFigures', $lga);

        return $this->form($lga, null, (string) $request->query('community', ''));
    }

    public function store(VulnerableFiguresRequest $request, Lga $lga, SaveVulnerableFigures $save): RedirectResponse
    {
        $this->authorize('addVulnerableFigures', $lga);

        $save->create($lga, $request->values(), $this->actor($request));

        return redirect()->route('lgas.show', ['lga' => $lga, 'tab' => 'vulnerable_population'])
            ->with('toast', ['type' => 'success', 'message' => 'Figures recorded.']);
    }

    public function edit(VulnerablePopulationStat $figure): Response
    {
        $this->authorize('update', $figure);

        return $this->form($figure->lga, $figure->load('community'), '');
    }

    public function update(VulnerableFiguresRequest $request, VulnerablePopulationStat $figure, SaveVulnerableFigures $save): RedirectResponse
    {
        $this->authorize('update', $figure);

        $save->update($figure, $request->values(), $this->actor($request));

        return redirect()->route('lgas.show', ['lga' => $figure->lga, 'tab' => 'vulnerable_population'])
            ->with('toast', ['type' => 'success', 'message' => 'Figures saved.']);
    }

    public function destroy(DeleteRecordRequest $request, VulnerablePopulationStat $figure, DeleteWithReason $delete): RedirectResponse
    {
        $this->authorize('delete', $figure);

        $delete($figure, 'vulnerable_figure', (string) $request->validated('reason'), $this->actor($request));

        return redirect()->route('lgas.show', ['lga' => $figure->lga, 'tab' => 'vulnerable_population'])
            ->with('toast', ['type' => 'success', 'message' => 'Figures deleted.']);
    }

    private function form(Lga $lga, ?VulnerablePopulationStat $figure, string $community): Response
    {
        $lga->loadMissing(['state', 'senatorialDistrict']);

        return Inertia::render('vulnerable-population/form', [
            'lga' => LgaPresenter::facts($lga),
            'figure' => $figure === null ? null : CommunityInformationPresenter::figure($figure),
            'communities' => $this->communityOptions($lga),
            'selectedCommunity' => $community,
            'categories' => CommunityInformationPresenter::options()['vulnerableCategories'],
            'today' => now()->toDateString(),
        ]);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
