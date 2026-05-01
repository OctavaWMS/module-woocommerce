<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce;

use WP_Error;

/**
 * WooCommerce logs: WooCommerce → Status → Logs → source {@see self::SOURCE}.
 * Every entry is prefixed octavawms_YYYY-MM-DD for quick filtering (WC also adds dated filenames).
 */
final class PluginLog
{
    public const SOURCE = 'octavawms';

    /**
     * Prefix for every logged line/message body (grep-friendly): octavawms_2026-04-30
     */
    public static function linePrefix(): string
    {
        return 'octavawms_' . gmdate('Y-m-d');
    }

    /**
     * Single entrypoint for WooCommerce structured logs.
     *
     * Typical $context shapes:
     *   - Nested `request` + `response` from {@see self::httpExchange()} or REST helpers below.
     *   - Semantic errors (no outbound HTTP): set `request`/`response` to null or omit and add `note`.
     *
     * @param 'emergency'|'alert'|'critical'|'error'|'warning'|'notice'|'info'|'debug' $level
     */
    public static function log(string $level, string $subsystem, array $context = []): void
    {
        if (! function_exists('wc_get_logger')) {
            return;
        }

        $head = self::linePrefix() . ' ' . $subsystem;
        $line = $context === []
            ? $head
            : $head . ' | ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        wc_get_logger()->log($level, $line, ['source' => self::SOURCE]);
    }

    /**
     * Build standard `request` + `response` from a WP HTTP result (transport error or HTTP reply).
     *
     * @param string|array<string, mixed>|null $requestBody Raw JSON string, or associative body (redacted)
     */
    public static function httpExchange(string $method, string $url, array $requestHeaders, string|array|null $requestBody, mixed $wpResult): array
    {
        $reqBodyLog = self::normalizeRequestBodyForLog($requestBody);

        $out = [
            'request' => [
                'method' => $method,
                'url' => $url,
                'headers' => self::redactOutgoingRequestHeaders($requestHeaders),
                'body' => $reqBodyLog,
            ],
        ];

        if ($wpResult instanceof WP_Error) {
            $out['response'] = [
                'transport_error' => true,
                'error_code' => $wpResult->get_error_code(),
                'error_message' => self::truncate($wpResult->get_error_message()),
            ];

            return $out;
        }

        if (! is_array($wpResult)) {
            $out['response'] = ['parse_note' => 'wp_remote_* did not return an array'];

            return $out;
        }

        $raw = (string) wp_remote_retrieve_body($wpResult);
        $status = (int) wp_remote_retrieve_response_code($wpResult);

        $out['response'] = [
            'http_status' => $status,
            'headers' => self::redactResponseHeadersForLog(
                self::flattenWpResponseHeaders(wp_remote_retrieve_headers($wpResult))
            ),
            'body' => self::truncate($raw, 6000),
        ];

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $out['response']['json'] = self::redactApiResponseDataForLog($decoded);
        }

        return $out;
    }

    /**
     * Response block when the HTTP stack is not wp_remote_* (e.g. internal {@see BackendApiClient::request}).
     *
     * @param array<string, string> $responseHeaders Already flattened + safe, or raw flat array
     */
    public static function responseFromFetched(int $httpStatus, array $responseHeaders, string $rawBody, ?array $decodedJson): array
    {
        $block = [
            'http_status' => $httpStatus,
            'headers' => self::redactResponseHeadersForLog(self::truncateHeaderValues($responseHeaders)),
            'body' => self::truncate($rawBody, 6000),
        ];

        if ($decodedJson !== null) {
            $block['json'] = self::redactApiResponseDataForLog($decodedJson);
        }

        return ['response' => $block];
    }

    /**
     * @param array<string, mixed>|null $decodedJson
     */
    public static function importFailureContext(
        string $extId,
        int $sourceId,
        string $userMessage,
        bool $bearerTokenConfigured,
        array $requestBlock,
        int $httpStatus,
        array $responseHeadersFlat,
        string $rawBody,
        ?array $decodedJson,
    ): array {
        return array_merge([
            'ext_id' => $extId,
            'source_id' => $sourceId,
            'user_message' => $userMessage,
            'bearer_token_configured' => $bearerTokenConfigured,
            'request' => $requestBlock,
        ], self::responseFromFetched($httpStatus, $responseHeadersFlat, $rawBody, $decodedJson));
    }

    /**
     * @param array<string, string> $flat
     *
     * @return array<string, string>
     */
    private static function truncateHeaderValues(array $flat): array
    {
        $out = [];
        foreach ($flat as $k => $v) {
            $out[(string) $k] = self::truncate((string) $v, 800);
        }

        return $out;
    }

    /**
     * @param string|array<string, mixed>|null $body
     *
     * @return array|string
     */
    private static function normalizeRequestBodyForLog(string|array|null $body): array|string
    {
        if ($body === null) {
            return [];
        }

        if (is_array($body)) {
            $base = self::redactApiResponseDataForLog($body) ?? [];
            foreach (self::redactConnectRequestBody($body) as $k => $v) {
                $base[(string) $k] = $v;
            }

            return $base;
        }

        $t = self::truncate($body, 8000);
        $j = json_decode($t, true);
        if (is_array($j)) {
            $base = self::redactApiResponseDataForLog($j) ?? [];
            foreach (self::redactConnectRequestBody($j) as $k => $v) {
                $base[(string) $k] = $v;
            }

            return $base;
        }

        return $t;
    }

    /**
     * Redact likely secrets and truncate long strings for safe logging of JSON API bodies.
     *
     * @param array<string, mixed>|null $data
     *
     * @return array<string, mixed>|null
     */
    public static function redactApiResponseDataForLog(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        return self::redactSensitiveArray($data, 0, 6);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private static function redactSensitiveArray(array $data, int $depth, int $maxDepth): array
    {
        if ($depth >= $maxDepth) {
            return ['_note' => 'max_depth'];
        }

        $out = [];
        foreach ($data as $k => $v) {
            $key = (string) $k;
            if (self::isSensitiveKey($key)) {
                if (is_string($v)) {
                    $out[$key] = self::tokenMaskedPreview($v);
                } elseif (is_scalar($v) && $v !== '') {
                    $out[$key] = self::tokenMaskedPreview((string) $v);
                } else {
                    $out[$key] = '[redacted]';
                }

                continue;
            }
            if (is_array($v)) {
                /** @var array<string, mixed> $v */
                $out[$key] = self::redactSensitiveArray($v, $depth + 1, $maxDepth);
            } elseif (is_string($v)) {
                $out[$key] = self::truncate($v, 2000);
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $lk = strtolower($key);
        $needles = ['password', 'secret', 'token', 'apikey', 'api_key', 'authorization', 'credential', 'bearer'];

        foreach ($needles as $n) {
            if (str_contains($lk, $n)) {
                return true;
            }
        }

        if ($lk === 'auth' || str_ends_with($lk, '_secret')) {
            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $body Subset-safe for connect payloads
     *
     * @return array<string, string>
     */
    public static function redactConnectRequestBody(array $body): array
    {
        $out = [];
        if (isset($body['siteUrl'])) {
            $out['siteUrl'] = (string) $body['siteUrl'];
        }
        if (isset($body['adminEmail'])) {
            $out['adminEmail'] = self::maskEmail((string) $body['adminEmail']);
        }
        if (isset($body['storeName'])) {
            $out['storeName'] = (string) $body['storeName'];
        }

        return $out;
    }

    public static function truncate(string $text, int $max = 4000): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return mb_substr($text, 0, $max) . '…[truncated]';
    }

    /**
     * Safe log preview: first N graphemes + … + last M (Unicode-safe).
     *
     * Tokens of length ≤ (prefixLen + suffixLen) are logged as `***` + full value (no middle omitted).
     */
    public static function tokenMaskedPreview(string $value, int $prefixLen = 3, int $suffixLen = 5): string
    {
        $value = trim($value);
        if ($value === '') {
            return '[empty]';
        }

        $l = mb_strlen($value);
        if ($l <= $prefixLen + $suffixLen) {
            return '***' . $value;
        }

        return mb_substr($value, 0, $prefixLen) . '…' . mb_substr($value, -$suffixLen);
    }

    /**
     * Format API {@code errors} for display (same shapes as Shopify edit-shipment): list of strings,
     * or objects with {@code message} or {@code code}; optionally {@code field} is ignored for text.
     *
     * @param mixed $errors Top-level or embedded {@code errors} value
     */
    public static function messagesFromApiErrorsField(mixed $errors): string
    {
        if ($errors === null) {
            return '';
        }
        /** @var list<string> $lines */
        $lines = [];
        /** @var list<mixed> $list */
        $list = is_array($errors)
            ? (array_is_list($errors) ? $errors : [$errors])
            : [$errors];
        foreach ($list as $error) {
            if (is_string($error)) {
                $t = trim($error);
                if ($t !== '') {
                    $lines[] = $t;
                }
            } elseif (is_array($error)) {
                if (isset($error['message']) && is_string($error['message'])) {
                    $t = trim($error['message']);
                    if ($t !== '') {
                        $lines[] = $t;
                    }
                } elseif (isset($error['code']) && (is_string($error['code']) || is_numeric($error['code']))) {
                    $t = trim((string) $error['code']);
                    if ($t !== '') {
                        $lines[] = $t;
                    }
                } else {
                    $enc = json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if (is_string($enc) && $enc !== '' && $enc !== '[]' && $enc !== '{}') {
                        $lines[] = $enc;
                    }
                }
            } elseif (is_scalar($error) && ! is_bool($error)) {
                $t = trim((string) $error);
                if ($t !== '') {
                    $lines[] = $t;
                }
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Map API / problem+json style bodies into a short user-visible message.
     * Structured {@code errors} (field/message objects) are included first, then {@code detail},
     * then legacy single-string fields, then {@code title} — same priority idea as Shopify shipment errors.
     *
     * @param array<string, mixed>|null $data Top-level decoded JSON object
     */
    public static function userMessageFromApiJson(?array $data, string $default): string
    {
        if ($data === null) {
            return $default;
        }

        $errorsBlock = self::messagesFromApiErrorsField($data['errors'] ?? null);
        $detailTrimmed = isset($data['detail']) && is_string($data['detail']) ? trim($data['detail']) : '';

        /** @var list<string> $segments */
        $segments = [];
        if ($errorsBlock !== '') {
            $segments[] = $errorsBlock;
        }
        if ($detailTrimmed !== '' && ($errorsBlock === '' || mb_stripos($errorsBlock, $detailTrimmed) === false)) {
            $segments[] = $detailTrimmed;
        }

        foreach (['errorMessage', 'error', 'message'] as $k) {
            if (! isset($data[$k])) {
                continue;
            }
            $v = $data[$k];
            $t = '';
            if (is_string($v)) {
                $t = trim($v);
            } elseif ($v !== null && ! is_array($v) && ! is_object($v)) {
                $t = trim((string) $v);
            }
            if ($t === '') {
                continue;
            }
            if ($errorsBlock !== '' && mb_stripos($errorsBlock, $t) !== false) {
                continue;
            }
            if ($detailTrimmed !== '' && $t === $detailTrimmed) {
                continue;
            }
            $segments[] = $t;
            break;
        }

        if ($segments !== []) {
            return implode("\n", $segments);
        }

        if (isset($data['title']) && is_string($data['title'])) {
            $titleTrimmed = trim($data['title']);
            if ($titleTrimmed !== '') {
                return $titleTrimmed;
            }
        }

        return $default;
    }

    /**
     * Human-readable errors from a delivery request entity (embedded list row or GET-by-id body).
     * Matches Shopify edit-shipment {@code getShipmentError}: {@code errors} (strings or objects with
     * {@code message}/{@code code}) first, then non-empty {@code deliveryServiceStatus}, then
     * {@see userMessageFromApiJson} for problem+json-style bodies.
     *
     * @param array<string, mixed> $shipment
     */
    public static function shipmentErrorMessageFromApiShipment(array $shipment): string
    {
        /** @var list<string> $messages */
        $messages = [];

        if (array_key_exists('errors', $shipment) && $shipment['errors'] !== null) {
            $errStr = self::messagesFromApiErrorsField($shipment['errors']);
            if ($errStr !== '') {
                $messages[] = $errStr;
            }
        }

        if (isset($shipment['deliveryServiceStatus']) && is_string($shipment['deliveryServiceStatus'])) {
            $t = trim($shipment['deliveryServiceStatus']);
            if ($t !== '') {
                $messages[] = $t;
            }
        }

        if ($messages !== []) {
            return implode("\n", $messages);
        }

        return self::userMessageFromApiJson($shipment, '');
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public static function redactOutgoingRequestHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $lk = strtolower((string) $k);
            if ($lk === 'authorization') {
                $out[$k] = self::redactAuthorizationHeaderValue((string) $v);
            } else {
                $out[$k] = (string) $v;
            }
        }

        return $out;
    }

    /**
     * @param mixed $headers Value from wp_remote_retrieve_headers()
     *
     * @return array<string, string>
     */
    public static function flattenWpResponseHeaders(mixed $headers): array
    {
        $out = [];
        if (is_array($headers)) {
            foreach ($headers as $k => $v) {
                $out[(string) $k] = self::stringifyHeaderValue($v);
            }

            return $out;
        }
        if (is_object($headers) && $headers instanceof \Traversable) {
            foreach ($headers as $k => $v) {
                $out[(string) $k] = self::stringifyHeaderValue($v);
            }
        }

        return $out;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    public static function redactResponseHeadersForLog(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $lk = strtolower((string) $k);
            if ($lk === 'set-cookie') {
                $out[$k] = '[present; redacted]';
            } elseif ($lk === 'authorization') {
                $out[$k] = self::redactAuthorizationHeaderValue((string) $v);
            } else {
                $out[$k] = self::truncate((string) $v, 800);
            }
        }

        return $out;
    }

    private static function stringifyHeaderValue(mixed $v): string
    {
        if (is_array($v)) {
            return implode(', ', array_map(static fn ($x) => (string) $x, $v));
        }

        return (string) $v;
    }

    private static function redactAuthorizationHeaderValue(string $value): string
    {
        if (preg_match('/^Basic\s+(.+)/i', trim($value), $m)) {
            return 'Basic ' . self::tokenMaskedPreview($m[1]);
        }
        if (preg_match('/^OctavaWMS\s+(.+)/i', trim($value), $m)) {
            $suffix = preg_replace_callback(
                '/signature=([^,\s]+)/i',
                /** @param array<int|string, string> $sig */
                function (array $sig): string {
                    return 'signature=' . self::tokenMaskedPreview($sig[1]);
                },
                $m[1]
            );

            return 'OctavaWMS ' . (is_string($suffix) ? $suffix : $m[1]);
        }
        if (preg_match('/^Bearer\s+(.+)/i', trim($value), $m)) {
            return 'Bearer ' . self::tokenMaskedPreview(trim($m[1]));
        }

        return '[redacted]';
    }

    private static function maskEmail(string $email): string
    {
        $email = trim($email);
        if ($email === '' || ! str_contains($email, '@')) {
            return '[invalid or empty]';
        }
        [$local, $domain] = explode('@', $email, 2);
        $prefix = $local !== '' ? $local[0] . '***' : '*';

        return $prefix . '@' . $domain;
    }
}
