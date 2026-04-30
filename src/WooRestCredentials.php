<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

final class WooRestCredentials
{
    /**
     * Match WC REST keys whose description was set by OctavaWMS via wc-auth (`app_name=OctavaWMS`).
     * WooCommerce stores the description as e.g. "OctavaWMS - API (2026-04-26 23:15:01)".
     */
    public const DESCRIPTION_LIKE = 'OctavaWMS%';

    /**
     * Read the OctavaWMS WooCommerce REST key row.
     *
     * `consumer_secret` is stored in plaintext by WooCommerce; `truncated_key` is the last 7 chars
     * of the full `ck_…` key (WooCommerce only keeps a hash of the full key).
     *
     * @return array{consumer_secret: string, key_last7: string, description: string, user_id: int}|null
     */
    public static function findOctavawmsKey(): ?array
    {
        global $wpdb;
        if (! isset($wpdb) || ! is_object($wpdb)) {
            return null;
        }
        if (! property_exists($wpdb, 'prefix') || ! is_string($wpdb->prefix)) {
            return null;
        }
        if (! method_exists($wpdb, 'prepare') || ! method_exists($wpdb, 'get_row')) {
            return null;
        }

        $table = $wpdb->prefix . 'woocommerce_api_keys';
        $sql = $wpdb->prepare(
            "SELECT consumer_secret, truncated_key, description, user_id
               FROM {$table}
              WHERE description LIKE %s
              ORDER BY last_access DESC, key_id DESC
              LIMIT 1",
            self::DESCRIPTION_LIKE
        );

        $row = $wpdb->get_row($sql, defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A');
        if (! is_array($row)) {
            return null;
        }

        $secret = isset($row['consumer_secret']) ? (string) $row['consumer_secret'] : '';
        $last7  = isset($row['truncated_key']) ? (string) $row['truncated_key'] : '';
        if ($secret === '' || $last7 === '') {
            return null;
        }

        return [
            'consumer_secret' => $secret,
            'key_last7' => $last7,
            'description' => isset($row['description']) ? (string) $row['description'] : '',
            'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : 0,
        ];
    }

    /**
     * Build the `Authorization: OctavaWMS …` header value from credentials and the JSON body.
     *
     * Signed input is `ts . "." . nonce . "." . bodyJson`. HMAC-SHA256, base64 output.
     * The backend is expected to recompute the same signature with its stored `auth.secret` and
     * reject timestamps that drift more than ~5 minutes.
     *
     * @param array{consumer_secret: string, key_last7: string} $creds
     *
     * @return array{header: string, key_last7: string, ts: string, nonce: string, signature: string}
     */
    public static function signConnectRequest(array $creds, string $bodyJson): array
    {
        $ts = (string) time();
        $nonce = bin2hex(random_bytes(8));
        $toSign = $ts . '.' . $nonce . '.' . $bodyJson;
        $sig = base64_encode(hash_hmac('sha256', $toSign, $creds['consumer_secret'], true));

        $header = sprintf(
            'OctavaWMS key_last7=%s, ts=%s, nonce=%s, algo=HMAC-SHA256, signature=%s',
            $creds['key_last7'],
            $ts,
            $nonce,
            $sig
        );

        return [
            'header' => $header,
            'key_last7' => $creds['key_last7'],
            'ts' => $ts,
            'nonce' => $nonce,
            'signature' => $sig,
        ];
    }
}
