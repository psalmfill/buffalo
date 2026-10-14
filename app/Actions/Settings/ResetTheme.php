<?php

namespace App\Actions\Settings;

use App\Models\AppSetting;
use App\Models\User;
use App\Support\Theme\Theme;
use Illuminate\Support\Facades\DB;

/**
 * Back to the default colours (Phase 11A.3), audit-logged with the old and
 * new values.
 */
final class ResetTheme
{
    public function __invoke(User $by): void
    {
        $old = Theme::current();

        DB::transaction(function () use ($old, $by): void {
            AppSetting::forget(Theme::KEY);

            activity('settings')
                ->causedBy($by)
                ->event('theme_reset')
                ->withProperties(['old' => $old, 'new' => Theme::defaults()])
                ->log('Theme colours reset to defaults');
        });

        Theme::forgetCache();
    }
}
