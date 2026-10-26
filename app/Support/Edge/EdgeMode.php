<?php

namespace App\Support\Edge;

/**
 * The edge mode: how the nginx container meets the outside world.
 *
 * - `direct` (dedicated server): nginx publishes 80 and 443 on all
 *   interfaces and terminates TLS; no forwarded headers are trusted.
 * - `proxy` (shared server): nginx publishes plain HTTP on
 *   127.0.0.1:BUFFALO_EDGE_PORT only, behind a host nginx that terminates
 *   TLS; forwarded headers are trusted only from the Docker gateway.
 * - `local` (UAT only): the local UAT environment, TLS here on
 *   127.0.0.1:19443.
 *
 * The chosen mode comes from the installation settings; what nginx
 * publishes comes from the compose files (compose.prod.yml,
 * compose.edge-proxy.yml). Used by buffalo:production:check (which refuses
 * to start on any problem), the go-live check and System health. Settings
 * and addresses only, never secrets.
 */
final class EdgeMode
{
    public const DIRECT = 'direct';

    public const PROXY = 'proxy';

    public const LOCAL = 'local';

    public static function mode(): string
    {
        return strtolower(trim((string) config('buffalo.edge.mode')));
    }

    /** What the compose files publish (`direct` or `proxy`), or null outside the release compose files. */
    public static function published(): ?string
    {
        $published = trim((string) config('buffalo.edge.published'));

        return $published === '' ? null : $published;
    }

    public static function bind(): string
    {
        return trim((string) config('buffalo.edge.bind'));
    }

    /** @return list<string> the published host ports */
    public static function ports(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) config('buffalo.edge.ports'))), fn (string $p) => $p !== ''));
    }

    /**
     * Why the edge settings are unsafe or inconsistent; empty when they are fine.
     *
     * @return list<string>
     */
    public static function problems(bool $uat = false): array
    {
        $problems = [];
        if (! $uat && (bool) config('buffalo.edge.basic_auth')) {
            $problems[] = 'BUFFALO_BASIC_AUTH (the site-wide password) is for the UAT environment only';
        }

        $mode = self::mode();
        $allowed = $uat ? [self::DIRECT, self::PROXY, self::LOCAL] : [self::DIRECT, self::PROXY];
        if (! in_array($mode, $allowed, true)) {
            return [...$problems, 'BUFFALO_EDGE must be '.implode(' or ', $allowed)];
        }

        $published = self::published();
        $expected = $mode === self::PROXY ? self::PROXY : self::DIRECT;

        if ($published === null) {
            $problems[] = 'The compose files do not say what nginx publishes (BUFFALO_EDGE_PUBLISHED): start BUFFALO with the release\'s compose files';
        } elseif ($published !== $expected) {
            $problems[] = $mode === self::PROXY
                ? 'BUFFALO_EDGE=proxy, but compose.edge-proxy.yml is not in COMPOSE_FILE (nginx publishes 80 and 443)'
                : "BUFFALO_EDGE={$mode}, but COMPOSE_FILE includes compose.edge-proxy.yml";
        }

        if ($published === self::PROXY && self::bind() !== '127.0.0.1') {
            $problems[] = 'Edge mode proxy publishes on 127.0.0.1 only: BUFFALO_EDGE_BIND must be 127.0.0.1 or unset';
        }

        return $problems;
    }

    /** One line for people: the mode, address and ports. */
    public static function summary(): string
    {
        $ports = implode(' and ', self::ports());
        $bind = self::bind() === '' ? '?' : self::bind();

        return match (self::mode()) {
            self::PROXY => "Proxy: plain HTTP on {$bind}:{$ports} behind the host's nginx, which terminates TLS. Forwarded headers are trusted only from the Docker gateway.",
            self::LOCAL => "Local UAT: TLS in BUFFALO's nginx on {$bind}, ports {$ports}.",
            default => "Direct: BUFFALO's nginx terminates TLS on {$bind}, ports {$ports}. No forwarded headers are trusted.",
        };
    }

    /**
     * @return array{mode: string, published: string|null, bind: string, ports: list<string>, basic_auth: bool, summary: string, problems: list<string>}
     */
    public static function report(bool $uat = false): array
    {
        return [
            'mode' => self::mode(),
            'published' => self::published(),
            'bind' => self::bind(),
            'ports' => self::ports(),
            'basic_auth' => (bool) config('buffalo.edge.basic_auth'),
            'summary' => self::summary(),
            'problems' => self::problems($uat),
        ];
    }
}
