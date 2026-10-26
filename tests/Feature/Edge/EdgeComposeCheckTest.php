<?php

use App\Console\Commands\EdgeComposeCheckCommand;

/*
| Edge modes: the merged Compose configuration must publish what the edge
| mode allows (run by install.sh, upgrade.sh and make uat-up).
*/

/**
 * @param  list<array<string, mixed>>  $nginxPorts
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function composeConfig(string $mode, array $nginxPorts, array $extra = [], string $env = 'production'): array
{
    return ['services' => [
        'app' => ['environment' => ['BUFFALO_EDGE' => $mode, 'APP_ENV' => $env]],
        'nginx' => ['ports' => $nginxPorts],
        'postgres' => [],
        ...$extra,
    ]];
}

function port(string $hostIp, int $published, int $target): array
{
    return ['mode' => 'ingress', 'host_ip' => $hostIp, 'published' => (string) $published, 'target' => $target, 'protocol' => 'tcp'];
}

test('proxy: one port on 127.0.0.1 to the proxy server passes', function () {
    expect(EdgeComposeCheckCommand::problems(composeConfig('proxy', [port('127.0.0.1', 8090, 8081)])))->toBe([]);
});

test('direct: 80 and 443 on all interfaces pass', function () {
    expect(EdgeComposeCheckCommand::problems(composeConfig('direct', [port('0.0.0.0', 80, 8080), port('0.0.0.0', 443, 8443)])))->toBe([]);
});

test('each unsafe or inconsistent publication is refused', function (array $config, string $message) {
    expect(implode("\n", EdgeComposeCheckCommand::problems($config)))->toContain($message);
})->with([
    'proxy on all interfaces' => [composeConfig('proxy', [port('0.0.0.0', 8090, 8081)]), 'only 127.0.0.1 is allowed'],
    'proxy on IPv6 any' => [composeConfig('proxy', [port('::', 8090, 8081)]), 'only 127.0.0.1 is allowed'],
    'proxy plus an extra port' => [composeConfig('proxy', [port('127.0.0.1', 8090, 8081), port('0.0.0.0', 443, 8443)]), 'exactly one port'],
    'proxy without the overlay' => [composeConfig('proxy', [port('0.0.0.0', 80, 8080), port('0.0.0.0', 443, 8443)]), 'exactly one port'],
    'direct with the proxy overlay' => [composeConfig('direct', [port('127.0.0.1', 8090, 8081)]), 'remove compose.edge-proxy.yml'],
    'another service publishes' => [composeConfig('direct', [port('0.0.0.0', 443, 8443)], ['postgres' => ['ports' => [port('0.0.0.0', 5432, 5432)]]]), 'postgres publishes a port'],
    'unknown mode' => [composeConfig('tunnel', []), 'BUFFALO_EDGE must be direct or proxy'],
    'local outside UAT' => [composeConfig('local', [port('127.0.0.1', 19443, 8443)]), 'BUFFALO_EDGE must be direct or proxy'],
]);

test('UAT also accepts the local mode', function () {
    expect(EdgeComposeCheckCommand::problems(composeConfig('local', [port('127.0.0.1', 19443, 8443)], env: 'uat')))->toBe([]);
});

test('the real compose files: proxy and direct overlays publish what their modes allow', function () {
    // The same files install.sh combines; parsed by the command as in production.
    $dir = base_path();
    expect(is_file("{$dir}/compose.edge-proxy.yml"))->toBeTrue()
        ->and((string) file_get_contents("{$dir}/compose.edge-proxy.yml"))
        ->toContain('"${BUFFALO_EDGE_BIND:-127.0.0.1}:${BUFFALO_EDGE_PORT:-8090}:8081"', 'secrets: !reset []');
});
