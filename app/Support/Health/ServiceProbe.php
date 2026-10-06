<?php

namespace App\Support\Health;

use App\Support\Malware\MalwareScanner;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Throwable;

/**
 * Probes each service of the stack (Phase 8C.4) from the application: the
 * database, Valkey, Horizon, Reverb, Gotenberg, ClamAV and nginx. Each
 * probe is short (2–3 s timeouts) and reports status only. Tests bind a
 * fake (Tests\TestCase).
 */
class ServiceProbe
{
    /**
     * @return array<string, array{ok: bool, detail: string}>
     */
    public function all(): array
    {
        return [
            'PostgreSQL' => $this->probe(fn () => 'PostgreSQL '.explode(' ', (string) DB::scalar('SHOW server_version'))[0].', '.DB::scalar('SELECT count(*) FROM pg_stat_activity').' connections'),
            'Valkey' => $this->probe(function () {
                Redis::connection()->ping();
                $info = Redis::connection()->command('info', ['memory']);
                $used = is_array($info) ? ($info['used_memory_human'] ?? $info['Memory']['used_memory_human'] ?? null) : null;

                return 'Responding'.($used === null ? '' : ", {$used} used");
            }),
            'Horizon' => $this->probe(function () {
                $masters = app(MasterSupervisorRepository::class)->all();
                if ($masters === []) {
                    throw new \RuntimeException('No queue workers are running.');
                }
                $paused = collect($masters)->contains(fn ($m) => ($m->status ?? null) === 'paused');
                if ($paused) {
                    throw new \RuntimeException('Queue workers are paused.');
                }

                return count($masters).' supervisor(s) running';
            }),
            'Reverb' => $this->probe(fn () => $this->socket((string) config('broadcasting.connections.reverb.options.host', 'reverb'), (int) config('broadcasting.connections.reverb.options.port', 8080))),
            'Gotenberg' => $this->probe(function () {
                $response = Http::timeout(3)->get(rtrim((string) config('buffalo.reports.gotenberg_url'), '/').'/health');
                $response->throw();

                return 'Healthy';
            }),
            'ClamAV' => $this->probe(function () {
                $version = app(MalwareScanner::class)->version();

                return $version->engine.', signatures '.($version->signatureAgeDays() === null ? 'of unknown age' : $version->signatureAgeDays().' days old');
            }),
            'nginx' => $this->probe(function () {
                Http::timeout(3)->get((string) config('buffalo.health.nginx_url'))->throw();

                return 'Responding';
            }),
        ];
    }

    /** @return array{ok: bool, detail: string} */
    private function probe(callable $probe): array
    {
        try {
            return ['ok' => true, 'detail' => (string) $probe()];
        } catch (Throwable $e) {
            return ['ok' => false, 'detail' => mb_substr($e->getMessage() ?: $e::class, 0, 200)];
        }
    }

    private function socket(string $host, int $port): string
    {
        $socket = @fsockopen($host, $port, $code, $message, 2.0);
        if ($socket === false) {
            throw new \RuntimeException("Not reachable at {$host}:{$port}.");
        }
        fclose($socket);

        return 'Accepting connections';
    }
}
