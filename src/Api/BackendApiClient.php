<?php

declare(strict_types=1);

namespace OctavaWMS\WooCommerce\Api;

use OctavaWMS\WooCommerce\Options;
use OctavaWMS\WooCommerce\PluginLog;
use WP_Error;

class BackendApiClient
{
    public const LABEL_PATH = '/apps/woocommerce/api/label';

    public const OAUTH_PATH = '/oauth';

    public function getBaseUrl(): string
    {
        return Options::getBaseUrl();
    }

    /**
     * Generic authenticated HTTP request (JSON request / JSON response).
     *
     * Retries once after a 401 by calling refreshBearerToken().
     *
     * @return array{ok: bool, status: int, data: mixed, raw: string, response_headers: array<string, string>}
     */
    public function request(string $method, string $path, ?array $jsonBody = null, bool $retried = false): array
    {
        if (! $retried && Options::getApiKey() === '') {
            $this->refreshBearerToken();
        }

        $url = rtrim($this->getBaseUrl(), '/') . '/' . ltrim($path, '/');
        $headers = [
            'Accept' => 'application/json',
        ];
        if ($jsonBody !== null) {
            $headers['Content-Type'] = 'application/json';
        }
        $apiKey = Options::getApiKey();
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $args = [
            'method' => strtoupper($method),
            'timeout' => 45,
            'headers' => $headers,
        ];
        if ($jsonBody !== null) {
            $args['body'] = wp_json_encode($jsonBody);
        }

        $response = wp_remote_request($url, $args);
        if ($response instanceof WP_Error) {
            return [
                'ok' => false,
                'status' => 0,
                'data' => null,
                'raw' => $response->get_error_message(),
                'response_headers' => [],
            ];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 401 && ! $retried && $this->refreshBearerToken()) {
            return $this->request($method, $path, $jsonBody, true);
        }

        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        /** @var array<string, string> $flatHdr */
        $flatHdr = PluginLog::flattenWpResponseHeaders(wp_remote_retrieve_headers($response));

        return [
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($data) ? $data : null,
            'raw' => $raw,
            'response_headers' => $flatHdr,
        ];
    }

    /**
     * Obtain a Bearer access token: tries OAuth refresh first, then POST /apps/woocommerce/connect.
     */
    public function refreshBearerToken(): bool
    {
        if ($this->exchangeRefreshTokenForAccess()) {
            return true;
        }

        if (! function_exists('home_url') || ! function_exists('get_option')) {
            return false;
        }

        $siteUrl = (string) home_url();
        $adminEmail = (string) get_option('admin_email', '');
        if ($siteUrl === '' || $adminEmail === '') {
            return false;
        }

        $connectUrl = rtrim($this->getBaseUrl(), '/') . '/apps/woocommerce/connect';
        $bodyJson = (string) wp_json_encode([
            'siteUrl' => $siteUrl,
            'adminEmail' => $adminEmail,
            'storeName' => (string) get_bloginfo('name', 'display'),
        ]);
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        $creds = \OctavaWMS\WooCommerce\WooRestCredentials::findOctavawmsKey();
        if ($creds !== null) {
            $signed = \OctavaWMS\WooCommerce\WooRestCredentials::signConnectRequest($creds, $bodyJson);
            $headers['Authorization'] = $signed['header'];
        }

        $response = wp_remote_post($connectUrl, [
            'timeout' => 30,
            'headers' => $headers,
            'body' => $bodyJson,
        ]);

        if ($response instanceof WP_Error) {
            PluginLog::log('warning', 'connect_refresh', PluginLog::httpExchange('POST', $connectUrl, $headers, $bodyJson, $response));

            return false;
        }

        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            PluginLog::log(
                'warning',
                'connect_refresh',
                array_merge(
                    PluginLog::httpExchange('POST', $connectUrl, $headers, $bodyJson, $response),
                    ['parse_note' => 'refresh response is not JSON object']
                )
            );

            return false;
        }

        if ($this->ingestConnectResponseArray($data)) {
            return true;
        }

        PluginLog::log(
            'warning',
            'connect_refresh',
            array_merge(
                PluginLog::httpExchange('POST', $connectUrl, $headers, $bodyJson, $response),
                [
                    'response_json_redacted' => PluginLog::redactApiResponseDataForLog($data),
                    'denial_note' => 'connect did not yield apiKey or refreshToken+domain+oauth',
                ]
            )
        );

        return false;
    }

    /**
     * Exchange stored refresh token for an access token (Bearer) via POST /oauth.
     */
    public function exchangeRefreshTokenForAccess(): bool
    {
        $refresh = Options::getRefreshToken();
        $domain = Options::getOAuthDomain();
        if ($refresh === '' || $domain === '') {
            return false;
        }

        $url = (string) apply_filters('octavawms_oauth_url', rtrim($this->getBaseUrl(), '/') . self::OAUTH_PATH);
        $clientId = (string) apply_filters('octavawms_oauth_client_id', 'orderadmin');
        /** @var array<string, string> $payload */
        $payload = [
            'grant_type' => 'refresh_token',
            'client_id' => $clientId,
            'refresh_token' => $refresh,
            'domain' => $domain,
        ];
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        $response = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => $headers,
            'body' => (string) wp_json_encode($payload),
        ]);

        if ($response instanceof WP_Error) {
            PluginLog::log('warning', 'oauth_refresh', PluginLog::httpExchange('POST', $url, $headers, $payload, $response));

            return false;
        }

        $raw = (string) wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            PluginLog::log(
                'warning',
                'oauth_refresh',
                array_merge(
                    PluginLog::httpExchange('POST', $url, $headers, $payload, $response),
                    ['parse_note' => 'oauth response is not JSON object']
                )
            );

            return false;
        }

        $access = (string) ($data['access_token'] ?? $data['accessToken'] ?? '');
        if ($access === '') {
            PluginLog::log(
                'warning',
                'oauth_refresh',
                array_merge(
                    PluginLog::httpExchange('POST', $url, $headers, $payload, $response),
                    [
                        'response_json_redacted' => PluginLog::redactApiResponseDataForLog($data),
                        'denial_note' => 'oauth response missing access_token',
                    ]
                )
            );

            return false;
        }

        $newRefresh = (string) ($data['refresh_token'] ?? $data['refreshToken'] ?? '');
        Options::mergeAccessTokenFromOAuth($access, $newRefresh !== '' ? $newRefresh : null);

        return true;
    }

    /**
     * Apply JSON from POST /apps/woocommerce/connect (admin AJAX or refreshBearerToken).
     *
     * Supports legacy apiKey, or refreshToken + domain with a follow-up OAuth exchange.
     */
    public function ingestConnectResponseArray(array $data): bool
    {
        if (($data['status'] ?? '') !== 'ok') {
            return false;
        }

        $sourceId = (int) ($data['sourceId'] ?? $data['source_id'] ?? 0);
        $labelEndpoint = (string) ($data['labelEndpoint'] ?? $data['label_endpoint'] ?? '');
        if ($labelEndpoint === '') {
            $labelEndpoint = rtrim($this->getBaseUrl(), '/') . self::LABEL_PATH;
        }

        $apiKey = (string) ($data['apiKey'] ?? $data['api_key'] ?? '');
        $refreshToken = (string) ($data['refreshToken'] ?? $data['refresh_token'] ?? '');
        $domain = (string) ($data['domain'] ?? '');

        if ($apiKey !== '') {
            Options::saveCredentials($apiKey, $labelEndpoint, $sourceId);

            return true;
        }

        if ($refreshToken !== '' && $domain !== '') {
            Options::saveOAuthBootstrap($refreshToken, $domain, $labelEndpoint, $sourceId);

            return $this->exchangeRefreshTokenForAccess();
        }

        return false;
    }

    public function findOrderByExtId(string $extId): ?array
    {
        $query = $this->buildListQuery($extId, 'extId', 1, 1);
        $result = $this->request('GET', '/api/products/order?' . $query, null);
        if (! $result['ok'] || ! is_array($result['data'])) {
            return null;
        }
        $embedded = $result['data']['_embedded'] ?? null;
        if (! is_array($embedded)) {
            return null;
        }
        $orders = $embedded['order'] ?? [];
        if (! is_array($orders) || $orders === []) {
            return null;
        }

        $first = $orders[0];

        return is_array($first) ? $first : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findShipmentsByExtId(string $extId): array
    {
        $query = $this->buildListQuery($extId, 'extId', 25, 1);
        $result = $this->request('GET', '/api/delivery-services/requests?' . $query, null);
        if (! $result['ok'] || ! is_array($result['data'])) {
            return [];
        }
        $embedded = $result['data']['_embedded'] ?? null;
        if (! is_array($embedded)) {
            return [];
        }
        foreach (['deliveryRequest', 'deliveryRequests', 'delivery_requests', 'requests'] as $key) {
            if (! isset($embedded[$key])) {
                continue;
            }
            $list = $embedded[$key];
            if (is_array($list)) {
                /** @var list<array<string, mixed>> $out */
                $out = array_values(array_filter($list, 'is_array'));

                return $out;
            }
        }

        return [];
    }

    /**
     * @return array{ok: true, data?: mixed}|array{ok: false, message: string, status?: int, raw_excerpt?: string, data?: mixed}
     */
    public function importOrder(string $extId, int $sourceId): array
    {
        if ($sourceId <= 0) {
            return ['ok' => false, 'message' => 'OctavaWMS source is not configured. Connect the plugin under WooCommerce → Settings → Integrations.'];
        }

        $payload = [
            'extId' => '',
            'sourceData' => [
                'async' => false,
                'asyncMode' => ['Orderadmin\\Products\\Entity\\AbstractOrder' => true],
                'filters' => ['extId' => $extId],
            ],
            'source' => $sourceId,
        ];

        $result = $this->request('POST', '/api/integrations/import', $payload);
        if ($result['ok']) {
            return ['ok' => true, 'data' => $result['data']];
        }

        $msg = 'Import failed.';
        if (is_array($result['data'])) {
            $msg = PluginLog::userMessageFromApiJson($result['data'], $msg);
        } elseif ($result['raw'] !== '') {
            $msg = mb_substr($result['raw'], 0, 500);
        } else {
            $msg = sprintf('HTTP %d', $result['status']);
        }

        $rawExcerpt = PluginLog::truncate((string) $result['raw'], 4000);
        $diag = $this->importOutboundRequestDiagnostics($payload);

        PluginLog::log(
            'error',
            'import',
            PluginLog::importFailureContext(
                $extId,
                $sourceId,
                $msg,
                $diag['bearer_token_configured'],
                $diag['request'],
                $result['status'],
                $result['response_headers'],
                (string) $result['raw'],
                is_array($result['data']) ? $result['data'] : null
            )
        );

        return [
            'ok' => false,
            'message' => $msg,
            'status' => $result['status'],
            'raw_excerpt' => $rawExcerpt,
            'data' => $result['data'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{bearer_token_configured: bool, request: array{method:string, url:string, headers:array<string,string>, body:array|string}}
     */
    private function importOutboundRequestDiagnostics(array $payload): array
    {
        $url = rtrim($this->getBaseUrl(), '/') . '/api/integrations/import';
        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        $apiKey = Options::getApiKey();
        $bearerPresent = $apiKey !== '';
        if ($bearerPresent) {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        /** @var array<string, mixed> $bodyForLog */
        $bodyForLog = PluginLog::redactApiResponseDataForLog($payload) ?? [];

        return [
            'bearer_token_configured' => $bearerPresent,
            'request' => [
                'method' => 'POST',
                'url' => $url,
                'headers' => PluginLog::redactOutgoingRequestHeaders($headers),
                'body' => $bodyForLog,
            ],
        ];
    }

    /**
     * GET preprocessing tasks for a delivery request to resolve an existing task ID and queue ID.
     *
     * Calls: GET /api/delivery-services/delivery-request-service?action=tasks&filter[...]=deliveryRequestId
     *
     * @return array{ok: bool, task_id: int|null, queue_id: int|null}
     */
    public function findPreprocessingTasksForShipment(int $deliveryRequestId): array
    {
        $query = http_build_query([
            'action' => 'tasks',
            'order-by[0][type]' => 'field',
            'order-by[0][field]' => 'created',
            'order-by[0][direction]' => 'desc',
            'filter[0][field]' => 'id',
            'filter[0][alias]' => 'dr',
            'filter[0][type]' => 'eq',
            'filter[0][value]' => $deliveryRequestId,
        ], '', '&', PHP_QUERY_RFC3986);

        $result = $this->request('GET', '/api/delivery-services/delivery-request-service?' . $query);

        if (! $result['ok'] || ! is_array($result['data'])) {
            return ['ok' => false, 'task_id' => null, 'queue_id' => null];
        }

        $items = $result['data'];
        $taskId = null;
        $queueId = null;

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            if ($taskId === null && isset($item['task']['id']) && is_int($item['task']['id'])) {
                $taskId = $item['task']['id'];
            }
            if ($queueId === null && isset($item['queue']['queue']['id']) && is_int($item['queue']['queue']['id'])) {
                $queueId = $item['queue']['queue']['id'];
            }
        }

        return ['ok' => true, 'task_id' => $taskId, 'queue_id' => $queueId];
    }

    /**
     * POST (create) or PATCH (update) a preprocessing task.
     *
     * Sends Accept: application/pdf,text/html — if the backend returns a PDF synchronously
     * it is captured in the 'pdf' field; otherwise 'task_id' holds the integer ID for polling.
     *
     * @param array<string, mixed> $payload
     * @return array{ok: bool, pdf: string|null, content_type: string, task_id: int|null, message?: string}
     */
    public function createOrUpdatePreprocessingTask(?int $taskId, array $payload, bool $retried = false): array
    {
        if (! $retried && Options::getApiKey() === '') {
            $this->refreshBearerToken();
        }

        $path = '/api/delivery-services/preprocessing-task';
        $method = 'POST';
        if ($taskId !== null) {
            $path .= '/' . $taskId;
            $method = 'PATCH';
        }

        $url = rtrim($this->getBaseUrl(), '/') . $path;
        $headers = [
            'Accept' => 'application/pdf,text/html',
            'Content-Type' => 'application/json',
        ];
        $apiKey = Options::getApiKey();
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $response = wp_remote_request($url, [
            'method' => $method,
            'timeout' => 45,
            'headers' => $headers,
            'body' => (string) wp_json_encode($payload),
        ]);

        if ($response instanceof WP_Error) {
            PluginLog::log(
                'error',
                'labels_preprocessing',
                PluginLog::httpExchange($method, $url, $headers, $payload, $response)
            );

            return ['ok' => false, 'pdf' => null, 'content_type' => '', 'task_id' => null, 'message' => $response->get_error_message()];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status === 401 && ! $retried && $this->refreshBearerToken()) {
            return $this->createOrUpdatePreprocessingTask($taskId, $payload, true);
        }

        if ($status < 200 || $status >= 300) {
            PluginLog::log(
                'error',
                'labels_preprocessing',
                PluginLog::httpExchange($method, $url, $headers, $payload, $response)
            );

            return ['ok' => false, 'pdf' => null, 'content_type' => '', 'task_id' => $taskId, 'message' => sprintf('HTTP %d', $status)];
        }

        $contentType = strtolower((string) wp_remote_retrieve_response_header($response, 'content-type'));
        $body = (string) wp_remote_retrieve_body($response);

        if (str_contains($contentType, 'application/pdf') || str_contains($contentType, 'text/html')) {
            return ['ok' => true, 'pdf' => $body, 'content_type' => $contentType, 'task_id' => $taskId];
        }

        $data = json_decode($body, true);
        $newTaskId = is_array($data) && isset($data['id']) && is_int($data['id']) ? $data['id'] : $taskId;

        return ['ok' => true, 'pdf' => null, 'content_type' => $contentType, 'task_id' => $newTaskId];
    }

    /**
     * GET a preprocessing task expecting a PDF/HTML response when the task is ready.
     *
     * @return array{ok: bool, ready: bool, pdf: string|null, content_type: string, status: int}
     */
    public function downloadPreprocessingTaskLabel(int $taskId): array
    {
        $url = rtrim($this->getBaseUrl(), '/') . '/api/delivery-services/preprocessing-task/' . $taskId;
        $apiKey = Options::getApiKey();
        $headers = ['Accept' => 'application/pdf,text/html'];
        if ($apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $apiKey;
        }

        $response = wp_remote_request($url, [
            'method' => 'GET',
            'timeout' => 30,
            'headers' => $headers,
        ]);

        if ($response instanceof WP_Error) {
            PluginLog::log(
                'error',
                'labels_download',
                PluginLog::httpExchange('GET', $url, $headers, null, $response)
            );

            return ['ok' => false, 'ready' => false, 'pdf' => null, 'content_type' => '', 'status' => 0];
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $contentType = strtolower((string) wp_remote_retrieve_response_header($response, 'content-type'));
        $body = (string) wp_remote_retrieve_body($response);

        if ($status >= 200 && $status < 300 && (str_contains($contentType, 'application/pdf') || str_contains($contentType, 'text/html'))) {
            return ['ok' => true, 'ready' => true, 'pdf' => $body, 'content_type' => $contentType, 'status' => $status];
        }

        if ($status < 200 || $status >= 300) {
            PluginLog::log(
                'error',
                'labels_download',
                PluginLog::httpExchange('GET', $url, $headers, null, $response)
            );
        }

        return ['ok' => $status >= 200 && $status < 300, 'ready' => false, 'pdf' => null, 'content_type' => $contentType, 'status' => $status];
    }

    private function buildListQuery(string $value, string $filterField, int $perPage, int $page): string
    {
        $parts = [
            'per_page' => (string) $perPage,
            'page' => (string) $page,
            'order-by[0][type]' => 'field',
            'order-by[0][field]' => 'created',
            'order-by[0][direction]' => 'desc',
            'filter[0][type]' => 'eq',
            'filter[0][field]' => $filterField,
            'filter[0][value]' => $value,
        ];

        return http_build_query($parts, '', '&', PHP_QUERY_RFC3986);
    }
}
