<?php

/**
 * Connectivity probe for Octava cloud APIs.
 *
 * Requires `composer install` in the plugin root (Composer autoload).
 *
 * CLI:
 *   php scripts/check-pro-oawms-connection.php
 *     → probes standard cloud hosts: https://pro.oawms.com, https://alpha.orderadmin.eu, https://api.octavawms.com
 *   php scripts/check-pro-oawms-connection.php https://other.example.com
 *     → probes that host only
 *
 * Env: OCTAVAWMS_PROBE_BASE_URL (single-host mode), CONNECT_TIMEOUT, MAX_TIME (seconds).
 *
 * Query (web mode): optional `base=https://host` probes one host only; omit for both standard clouds.
 *
 * Web (upload via SFTP): set OCTAVAWMS_CONN_PROBE_SECRET, then open
 *   …/check-pro-oawms-connection.php?token=YOUR_SECRET
 * Delete the file after testing.
 */
declare(strict_types=1);

use OctavaWMS\WooCommerce\Admin\ConnectivityProbe;

// Web mode: empty = disabled (403). Non-empty must match query ?token=
const OCTAVAWMS_CONN_PROBE_SECRET = '';

$isCli = PHP_SAPI === 'cli';

if (! $isCli) {
    header('Content-Type: text/plain; charset=UTF-8');
    if (OCTAVAWMS_CONN_PROBE_SECRET === '' || ($_GET['token'] ?? '') !== OCTAVAWMS_CONN_PROBE_SECRET) {
        http_response_code(403);
        echo "Forbidden. Set OCTAVAWMS_CONN_PROBE_SECRET in this file and pass ?token=... or run via CLI.\n";
        exit(1);
    }
}

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (! is_readable($autoload)) {
    if ($isCli) {
        fwrite(STDERR, 'Composer autoload missing — run `composer install` in the plugin root.' . PHP_EOL);
    } else {
        http_response_code(500);
        echo "Composer autoload missing.\n";
    }
    exit(1);
}

require_once $autoload;

$connectTimeout = max(1, (int) (getenv('CONNECT_TIMEOUT') ?: (string) ($_GET['connect_timeout'] ?? ConnectivityProbe::DEFAULT_CONNECT_TIMEOUT)));
$maxTime        = max(1, (int) (getenv('MAX_TIME') ?: (string) ($_GET['max_time'] ?? ConnectivityProbe::DEFAULT_MAX_TIME)));

$explicitSingle = false;
$urlArg = '';
if ($isCli) {
    if (($argv[1] ?? '') !== '') {
        $urlArg = trim((string) $argv[1]);
        $explicitSingle = true;
    } else {
        $envBase = getenv('OCTAVAWMS_PROBE_BASE_URL');
        if (is_string($envBase) && $envBase !== '') {
            $urlArg = trim($envBase);
            $explicitSingle = true;
        }
    }
} else {
    $getBase = $_GET['base'] ?? '';
    if (is_string($getBase) && trim($getBase) !== '') {
        $urlArg = trim($getBase);
        $explicitSingle = true;
    }
}

if ($explicitSingle && $urlArg !== '') {
    echo ConnectivityProbe::buildReport($urlArg, $connectTimeout, $maxTime);
    exit(0);
}

echo ConnectivityProbe::buildFullDiagnosticsReport(null, $connectTimeout, $maxTime);
