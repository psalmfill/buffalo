<?php

namespace Tests;

use App\Support\Malware\MalwareScanner;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;
use Tests\Support\Malware\FakeMalwareScanner;

abstract class TestCase extends BaseTestCase
{
    protected FakeMalwareScanner $malware;

    protected function setUp(): void
    {
        parent::setUp();

        // Never reach clamd from ordinary tests (Phase 8B.4).
        $this->malware = new FakeMalwareScanner;
        $this->app->instance(MalwareScanner::class, $this->malware);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
