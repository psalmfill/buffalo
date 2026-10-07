<?php

namespace App\Support\GoLive;

/**
 * Where synthetic and demo data may be generated (Phase 9B.1): local
 * development and the UAT environment, never production. The generators'
 * commands are registered only in these environments (and in tests, which
 * prove they refuse elsewhere), so a production deployment does not list
 * them at all.
 */
final class DemoData
{
    /** Environments in which the generators run. */
    public const GENERATOR_ENVIRONMENTS = ['local', 'uat'];

    /** Environments in which the generator commands are registered. */
    public const REGISTERED_ENVIRONMENTS = ['local', 'uat', 'testing'];

    public static function allowed(): bool
    {
        return app()->environment(self::GENERATOR_ENVIRONMENTS);
    }
}
