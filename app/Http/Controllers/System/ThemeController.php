<?php

namespace App\Http\Controllers\System;

use App\Actions\Settings\ResetTheme;
use App\Actions\Settings\SaveTheme;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ThemeRequest;
use App\Models\AppSetting;
use App\Models\User;
use App\Support\Theme\Contrast;
use App\Support\Theme\FixedColours;
use App\Support\Theme\Theme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Theme page under Administration (Phase 11A.3): brand, chart and KPI
 * colours with contrast checks, a live preview and reset to defaults.
 */
class ThemeController extends Controller
{
    public function edit(): Response
    {
        $this->authorize('manageTheme', AppSetting::class);

        return Inertia::render('system/theme', [
            'theme' => Theme::current(),
            'defaults' => Theme::defaults(),
            'customised' => Theme::isCustomised(),
            'backgrounds' => Theme::BACKGROUNDS,
            'minimums' => ['text' => Contrast::TEXT, 'graphic' => Contrast::GRAPHIC],
            'kpiTypes' => Theme::KPI_TYPES,
            'fixed' => FixedColours::present(),
            // The stylesheet the layout serves, so a save or reset applies to
            // the open page at once (the layout's <style> is only in the first load).
            'css' => Theme::css(),
        ]);
    }

    public function update(ThemeRequest $request, SaveTheme $save): RedirectResponse
    {
        $this->authorize('manageTheme', AppSetting::class);

        $save($request->validated(), $this->actor($request));

        return redirect()->route('system.theme.edit')->with('toast', ['type' => 'success', 'message' => 'Theme colours saved.']);
    }

    public function destroy(Request $request, ResetTheme $reset): RedirectResponse
    {
        $this->authorize('manageTheme', AppSetting::class);

        $reset($this->actor($request));

        return redirect()->route('system.theme.edit')->with('toast', ['type' => 'success', 'message' => 'Theme colours reset to defaults.']);
    }

    private function actor(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
