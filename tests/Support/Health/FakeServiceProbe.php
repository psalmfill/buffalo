<?php

namespace Tests\Support\Health;

use App\Support\Health\ServiceProbe;

/**
 * Bound for every test (Tests\TestCase): every service is up unless a test
 * marks it down. ServiceProbe's real probes reach the Docker services.
 */
final class FakeServiceProbe extends ServiceProbe
{
    /** @var array<string, string> name => failure detail */
    public array $down = [];

    public function all(): array
    {
        $services = [];
        foreach (['PostgreSQL', 'Valkey', 'Horizon', 'Reverb', 'Gotenberg', 'ClamAV', 'nginx'] as $name) {
            $services[$name] = isset($this->down[$name]) ? ['ok' => false, 'detail' => $this->down[$name]] : ['ok' => true, 'detail' => 'Responding'];
        }

        return $services;
    }
}
