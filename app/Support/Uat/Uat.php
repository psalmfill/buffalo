<?php

namespace App\Support\Uat;

/**
 * The user acceptance testing environment (Phase 9B.5): APP_ENV=uat, the
 * production images and settings, synthetic data only. UAT-only features
 * (the banner, the feedback button) exist only here.
 */
final class Uat
{
    public const BANNER = 'UAT — TEST DATA ONLY';

    /** Accounts created by buffalo:uat:seed use this (reserved) domain. */
    public const DOMAIN = 'uat.buffalo.test';

    public static function enabled(): bool
    {
        return app()->environment('uat');
    }

    /**
     * Production or UAT: everything that protects production (key
     * fingerprint checks, no destructive database commands, the queue
     * supervisors) applies to UAT too.
     */
    public static function productionLike(): bool
    {
        return app()->environment(['production', 'uat']);
    }
}
