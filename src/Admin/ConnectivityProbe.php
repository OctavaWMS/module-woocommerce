<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Admin;

/**
 * Server-side outbound HTTPS timings (DNS / TCP / TLS / TTFB) for diagnosing timeouts.
 */
final class ConnectivityProbe
{
    /** Default timeouts mirror {@see scripts/check-pro-oawms-connection.php}. */
    public const DEFAULT_CONNECT_TIMEOUT = 15;

    public const DEFAULT_MAX_TIME = 45;

    /**
     * Standard Octava cloud API bases always probed together in Woo diagnostics.
     *
     * @var list<string>
     */
    public const STANDARD_CLOUD_BASES_FOR_DIAGNOSTICS = [
        'https://pro.oawms.com',
        'https://alpha.orderadmin.eu',
        'https://api.octavawms.com',
    ];

    /**
     * Probe one HTTPS API base only (standalone script single-URL mode).
     */
    public static function buildReport(
        string $baseUrl,
        int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        int $maxTime = self::DEFAULT_MAX_TIME
    ): string {
        return self::buildProbeBodyForSingleBase(
            self::canonicalHttpsBase(trim($baseUrl)),
            $connectTimeout,
            $maxTime
        )
            . "\nDone.\n";
    }

    /**
     * Probe the store's resolved API host (when non-empty) plus every {@see STANDARD_CLOUD_BASES_FOR_DIAGNOSTICS}.
     * Duplicates (same scheme+host as a standard endpoint) are skipped.
     */
    public static function buildFullDiagnosticsReport(
        ?string $configuredIntegrationBase,
        int $connectTimeout = self::DEFAULT_CONNECT_TIMEOUT,
        int $maxTime = self::DEFAULT_MAX_TIME
    ): string {
        $blocks = [];
        $seenHosts = [];

        $append = static function (
            string $roleLabel,
            string $baseNorm
        ) use (&$blocks, &$seenHosts, $connectTimeout, $maxTime): void {
            if ($baseNorm === '') {
                return;
            }
            $hk = self::hostKeyFromBaseUrl($baseNorm);
            if ($hk === '') {
                return;
            }
            if (isset($seenHosts[$hk])) {
                return;
            }
            $seenHosts[$hk] = true;

            $hostDisplay = '';
            $p = parse_url($baseNorm . '/');
            if (is_array($p) && isset($p['host']) && is_string($p['host'])) {
                $hostDisplay = $p['host'];
            }

            $blocks[] =
                sprintf(
                    '========================================== HOST: %s%s',
                    $hostDisplay !== '' ? $hostDisplay : $hk,
                    $roleLabel !== '' ? ' — ' . $roleLabel : ''
                )
                . "\n"
                . 'Base URL: ' . $baseNorm
                . "\n=========================================="
                . "\n\n"
                . self::buildProbeBodyForSingleBase($baseNorm, $connectTimeout, $maxTime);
        };

        $cfg = '';
        if ($configuredIntegrationBase !== null && trim($configuredIntegrationBase) !== '') {
            $cfg = self::canonicalHttpsBase(trim($configuredIntegrationBase));
        }
        if ($cfg !== '') {
            $append('Integration base (configured)', $cfg);
        }

        foreach (self::STANDARD_CLOUD_BASES_FOR_DIAGNOSTICS as $url) {
            $append('Standard cloud', self::canonicalHttpsBase(trim((string) $url)));
        }

        return implode("\n\n" . str_repeat('-', 56) . "\n\n", $blocks) . "\n\nDone.\n";
    }

    /** HTTP(S) canonical base URL; empty if invalid or missing https host. */
    private static function canonicalHttpsBase(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $url = rtrim(preg_replace('#\s+#', '', $url) ?? '', '/');
        $parts = parse_url($url);
        $schemeRaw = isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $hostRaw = isset($parts['host']) ? strtolower((string) $parts['host']) : '';

        if ($schemeRaw === '' || $hostRaw === '') {
            return '';
        }
        $scheme = in_array($schemeRaw, ['https', 'http'], true) ? $schemeRaw : 'https';

        return $scheme . '://' . $hostRaw;
    }

    private static function hostKeyFromBaseUrl(string $canonicalHttpsBaseUrl): string
    {
        $p = parse_url($canonicalHttpsBaseUrl . '/');
        if (! is_array($p) || ! isset($p['scheme'], $p['host'])) {
            return '';
        }

        return strtolower((string) $p['scheme']) . '://' . strtolower((string) $p['host']);
    }

    private static function buildProbeBodyForSingleBase(
        string $baseUrl,
        int $connectTimeout,
        int $maxTime
    ): string {
        $lines = [];

        $parts = parse_url($baseUrl . '/');
        $host = is_array($parts) && isset($parts['host']) && is_string($parts['host']) ? $parts['host'] : '';

        $lines[] = 'Probe URL base: ' . $baseUrl;
        $lines[] = sprintf('limits: connect_timeout=%ds max_time=%ds', $connectTimeout, $maxTime);
        $lines[] = '';

        self::curlProbeAppend(
            $lines,
            'HEAD apps/woocommerce/connect',
            $baseUrl . '/apps/woocommerce/connect',
            'HEAD',
            $connectTimeout,
            $maxTime,
            true
        );

        self::curlProbeAppend(
            $lines,
            'GET root',
            $baseUrl . '/',
            'GET',
            $connectTimeout,
            $maxTime,
            false
        );

        $lines[] = '=== DNS ===';
        if ($host !== '') {
            $resolved = @gethostbyname($host);
            if (is_string($resolved) && $resolved !== '' && $resolved !== $host) {
                $lines[] = 'gethostbyname: ' . $resolved;
            } else {
                $lines[] = 'gethostbyname: (no IPv4)';
            }
            if (function_exists('dns_get_record')) {
                foreach (@dns_get_record($host, DNS_A) ?: [] as $row) {
                    if (is_array($row) && ($row['type'] ?? '') === 'A' && isset($row['ip'])) {
                        $lines[] = 'A: ' . $row['ip'];
                    }
                }
                foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $row) {
                    if (is_array($row) && ($row['type'] ?? '') === 'AAAA' && isset($row['ipv6'])) {
                        $lines[] = 'AAAA: ' . $row['ipv6'];
                    }
                }
            }
        } else {
            $lines[] = '(could not parse host)';
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $lines mutates
     */
    private static function curlProbeAppend(
        array &$lines,
        string $label,
        string $url,
        string $method,
        int $connectTimeout,
        int $maxTime,
        bool $footnoteOnConnectHint
    ): void {
        $lines[] = '=== ' . $label . ' ===';

        if (! function_exists('curl_init')) {
            $lines[] = 'Skipped: php-curl not available.';
            $lines[] = '';

            return;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            $lines[] = 'curl_init failed';
            $lines[] = '';

            return;
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => max(1, $connectTimeout),
            CURLOPT_TIMEOUT => max(1, $maxTime),
        ];
        if ($method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        } else {
            $opts[CURLOPT_HTTPGET] = true;
        }
        curl_setopt_array($ch, $opts);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = $errno !== 0 ? curl_error($ch) : '';

        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $nw = (float) curl_getinfo($ch, CURLINFO_NAMELOOKUP_TIME);
        $conn = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $tls = (float) curl_getinfo($ch, CURLINFO_APPCONNECT_TIME);
        $ttfb = (float) curl_getinfo($ch, CURLINFO_STARTTRANSFER_TIME);
        $tot = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
        curl_close($ch);

        if ($errno !== 0 || $ok === false) {
            $lines[] = sprintf('curl error %s: %s', (string) $errno, $err);
        }
        $lines[] = sprintf(
            'status:%s namelookup:%.6fs connect:%.6fs appconnect:%.6fs starttransfer:%.6fs total:%.6fs',
            $code > 0 ? (string) $code : 'n/a',
            $nw,
            $conn,
            $tls > 0 ? $tls : 0.0,
            $ttfb,
            $tot
        );

        if ($footnoteOnConnectHint) {
            $lines[] = '(405 / 401 on this path usually still means the host is reachable)';
        }
        $lines[] = '';
    }
}
