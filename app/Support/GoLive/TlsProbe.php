<?php

namespace App\Support\GoLive;

use Carbon\CarbonImmutable;

/**
 * Reads the certificate nginx actually serves (Phase 9B.6): a TLS
 * connection to nginx on the internal network, with the server name from
 * APP_URL. Works for an own certificate and for ACME alike, without
 * mounting the certificate into the application containers. Expiry only:
 * the chain is not verified here (an external scan does that).
 */
class TlsProbe
{
    /** Expiry of the served certificate, or null when none could be read. */
    public function servedCertificateExpiry(): ?CarbonImmutable
    {
        $host = (string) config('buffalo.golive.tls_host');
        $port = (int) config('buffalo.golive.tls_port');
        $serverName = (string) (parse_url((string) config('app.url'), PHP_URL_HOST) ?: $host);

        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $serverName,
        ]]);

        $client = @stream_socket_client("ssl://{$host}:{$port}", $errno, $error, 5, STREAM_CLIENT_CONNECT, $context);
        if ($client === false) {
            return null;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        $parsed = $certificate === null ? false : openssl_x509_parse($certificate);

        return is_array($parsed) && isset($parsed['validTo_time_t'])
            ? CarbonImmutable::createFromTimestamp((int) $parsed['validTo_time_t'])
            : null;
    }
}
