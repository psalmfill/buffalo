<?php

namespace App\Console\Commands;

use App\Support\Edge\EdgeMode;
use Illuminate\Console\Command;

/**
 * Checks what the merged Compose configuration really publishes against the
 * edge mode, before anything starts (install.sh, upgrade.sh, make uat-up):
 *
 *   docker compose --env-file <settings> config --format json \
 *       | docker compose ... run --rm -T --no-deps tools php artisan buffalo:edge:check-compose
 *
 * The start-up check (buffalo:production:check) sees only what the compose
 * files tell the containers; this also catches a port added by hand in
 * another overlay. Rules:
 *
 * - proxy: nginx publishes exactly one port, on 127.0.0.1, to the proxy
 *   server (8081);
 * - direct and local: nginx publishes only its HTTP and HTTPS servers
 *   (8080, 8443), HTTPS included;
 * - no other service publishes any port.
 *
 * Prints service names, addresses and ports only.
 */
class EdgeComposeCheckCommand extends Command
{
    protected $signature = 'buffalo:edge:check-compose';

    protected $description = 'Check the published ports in `docker compose config --format json` (stdin) against the edge mode';

    public function handle(): int
    {
        $config = json_decode((string) stream_get_contents(STDIN), true);
        if (! is_array($config) || ! is_array($config['services'] ?? null)) {
            $this->components->error('Expected the output of `docker compose config --format json` on standard input.');

            return self::FAILURE;
        }

        $problems = self::problems($config);
        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->components->error($problem);
            }
            $this->components->error('The published ports do not match the edge mode: refusing to start.');

            return self::FAILURE;
        }

        $this->components->info('Published ports match edge mode '.self::mode($config).'.');

        return self::SUCCESS;
    }

    /** @param  array<string, mixed>  $config */
    private static function mode(array $config): string
    {
        $mode = $config['services']['app']['environment']['BUFFALO_EDGE'] ?? EdgeMode::DIRECT;

        return strtolower(trim(is_string($mode) ? $mode : ''));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function problems(array $config): array
    {
        /** @var array<string, array<string, mixed>> $services */
        $services = $config['services'];
        $mode = self::mode($config);
        $uat = ($services['app']['environment']['APP_ENV'] ?? null) === 'uat';
        $allowed = $uat ? [EdgeMode::DIRECT, EdgeMode::PROXY, EdgeMode::LOCAL] : [EdgeMode::DIRECT, EdgeMode::PROXY];
        if (! in_array($mode, $allowed, true)) {
            return ['BUFFALO_EDGE must be '.implode(' or ', $allowed)."; it is \"{$mode}\""];
        }

        $problems = [];
        foreach ($services as $name => $service) {
            if ($name !== 'nginx' && ($service['ports'] ?? []) !== []) {
                $problems[] = "{$name} publishes a port: only nginx may";
            }
        }

        /** @var list<array<string, mixed>> $ports */
        $ports = $services['nginx']['ports'] ?? [];
        $describe = fn (array $p) => sprintf('%s:%s->%s', $p['host_ip'] ?? '0.0.0.0', $p['published'] ?? '?', $p['target'] ?? '?');

        if ($mode === EdgeMode::PROXY) {
            if (count($ports) !== 1) {
                $problems[] = 'Edge mode proxy: nginx must publish exactly one port (it publishes '.(count($ports) ?: 'none').': '.implode(', ', array_map($describe, $ports)).')';
            }
            foreach ($ports as $port) {
                if (($port['host_ip'] ?? '') !== '127.0.0.1') {
                    $problems[] = 'Edge mode proxy: nginx publishes '.$describe($port).'; only 127.0.0.1 is allowed';
                }
                if ((int) ($port['target'] ?? 0) !== 8081) {
                    $problems[] = 'Edge mode proxy: '.$describe($port).' is not the proxy server (container port 8081): is compose.edge-proxy.yml in COMPOSE_FILE?';
                }
            }
        } else {
            $targets = array_map(fn (array $p) => (int) ($p['target'] ?? 0), $ports);
            foreach ($ports as $port) {
                if (! in_array((int) ($port['target'] ?? 0), [8080, 8443], true)) {
                    $problems[] = "Edge mode {$mode}: nginx publishes ".$describe($port).', which is not its HTTP or HTTPS server: remove compose.edge-proxy.yml from COMPOSE_FILE';
                }
            }
            if (! in_array(8443, $targets, true)) {
                $problems[] = "Edge mode {$mode}: nginx does not publish its HTTPS server (container port 8443)";
            }
        }

        return $problems;
    }
}
