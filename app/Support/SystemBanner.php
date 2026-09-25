<?php

namespace App\Support;

use App\Enums\BannerLevel;

/**
 * The optional full-width banner shown at the top of every page, including
 * sign-in and error pages (BUFFALO_BANNER_TEXT, BUFFALO_BANNER_LEVEL).
 */
final class SystemBanner
{
    /**
     * @return array{text: string, level: string}|null null when no text is configured
     */
    public static function current(): ?array
    {
        $text = trim((string) config('buffalo.banner.text'));

        if ($text === '') {
            return null;
        }

        // An unknown level falls back to "info" rather than breaking every page.
        $level = BannerLevel::tryFrom(strtolower(trim((string) config('buffalo.banner.level')))) ?? BannerLevel::Info;

        return ['text' => $text, 'level' => $level->value];
    }
}
