<?php

namespace App\Actions\Settings;

use App\Models\AppSetting;
use App\Models\User;
use App\Support\Theme\Theme;
use Illuminate\Support\Facades\DB;

/**
 * Saves the theme colours (Phase 11A.3), already validated by ThemeRequest,
 * with a named audit event carrying the old and new values.
 */
final class SaveTheme
{
    /**
     * @param  array<string, mixed>  $theme
     */
    public function __invoke(array $theme, User $by): void
    {
        $new = Theme::normalise($theme);
        $old = Theme::current();

        DB::transaction(function () use ($new, $old, $by): void {
            AppSetting::put(Theme::KEY, $new, $by);

            activity('settings')
                ->causedBy($by)
                ->event('theme_updated')
                ->withProperties(['old' => $old, 'new' => $new])
                ->log('Theme colours changed');
        });

        Theme::forgetCache();
    }
}
