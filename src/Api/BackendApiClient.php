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
     * @return array{ok: bool, status: int, data: mixed, raw: string, response_headers: array<string, string>, request: array{method:string, url:string, headers:array<string,string>, body:array|string}}
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

        $requestLog = PluginLog::requestFromOutbound((string) $args['method'], $url, $headers, $jsonBody);
        $response = wp_remote_request($url, $args);
        if ($response instanceof WP_Error) {
            return [
                'ok' => false,
                'status' => 0,
                'data' => null,
                'raw' => $response->get_error_message(),
                'response_headers' => [],
                'request' => $requestLog,
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
            'request' => $requestLog,
        ];
    }

    /**
     * Resolve refresh token for web panel auto-login: when a Bearer is available, always GET a fresh token
     * from the user authenticate endpoint and persist rotated refresh; otherwise try OAuth/connect,
     * then fall back to stored refresh if the site is not yet able to call the API.
     *
     * @return array{ok: bool, refresh_token: string, message: string}
     */
    public function getPanelLoginRefreshToken(): array
    {
        if (Options::getApiKey() === '') {
            $this->refreshBearerToken();
        }

        if (Options::getApiKey() !== '') {
            return $this->fetchPanelLoginRefreshTokenFromApi();
        }

        $stored = Options::getRefreshToken();
        if ($stored !== '') {
            return ['ok' => true, 'refresh_token' => $stored, 'message' => ''];
        }

        return [
            'ok' => false,
            'refresh_token' => '',
            'message' => __(
                'Connect the plugin under WooCommerce → Settings → Integrations first.',
                'octavawms'
            ),
        ];
    }

    /**
     * GET current user and panel refresh token from the API (always use for login when Bearer is available).
     *
     * @return array{ok: bool, refresh_token: string, message: string}
     */
    private function fetchPanelLoginRefreshTokenFromApi(): array
    {
        $user = $this->request('GET', 'api/users/users/0');
        if (! $user['ok'] || ! is_array($user['data'])) {
            return [
                'ok' => false,
                'refresh_token' => '',
                'message' => __('Could not open panel login (user lookup failed).', 'octavawms'),
            ];
        }

        $uid = $user['data']['id'] ?? $user['data']['userId'] ?? null;
        if (! is_numeric($uid) || (int) $uid <= 0) {
            return [
                'ok' => false,
                'refresh_token' => '',
                'message' => __('Could not open panel login (invalid user id).', 'octavawms'),
            ];
        }

        $auth = $this->request('GET', 'api/users/authenticate/' . (string) (int) $uid);
        if (! $auth['ok'] || ! is_array($auth['data'])) {
            return [
                'ok' => false,
                'refresh_token' => '',
                'message' => __('Could not open panel login (authenticate request failed).', 'octavawms'),
            ];
        }

        $rt = (string) ($auth['data']['refreshToken'] ?? $auth['data']['refresh_token'] ?? '');
        if ($rt === '') {
            return [
                'ok' => false,
                'refresh_token' => '',
                'message' => __('Could not open panel login (missing refresh token).', 'octavawms'),
            ];
        }

        $access = Options::getApiKey();
        if ($access !== '') {
            Options::mergeAccessTokenFromOAuth($access, $rt);
        }

        return ['ok' => true, 'refresh_token' => $rt, 'message' => ''];
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

        $orders = $this->parseOrdersFromProductsOrderListBody($result['data']);

        $first = $orders[0] ?? null;

        return is_array($first) ? $first : null;
    }

    /**
     * Best-effort first order object from import or list JSON (HAL _embedded or plain collections).
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>|null
     */
    public function extractFirstOrderFromCollectionJson(array $data): ?array
    {
        $orders = $this->parseOrdersFromProductsOrderListBody($data);
        if ($orders !== []) {
            return $orders[0];
        }
        foreach (['order', 'resource', 'entity'] as $k) {
            if (isset($data[$k]) && is_array($data[$k]) && (isset($data[$k]['id']) || isset($data[$k]['extId']) || isset($data[$k]['ext_id']))) {
                return $data[$k];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return list<array<string, mixed>>
     */
    private function parseOrdersFromProductsOrderListBody(array $data): array
    {
        $embedded = $data['_embedded'] ?? null;
        if (is_array($embedded)) {
            foreach (['order', 'orders', 'abstractOrder', 'abstractOrders'] as $key) {
                if (! isset($embedded[$key])) {
                    continue;
                }
                $normalized = $this->normalizeToListOfOrderArrays($embedded[$key]);
                if ($normalized !== []) {
                    return $normalized;
                }
            }
        }

        foreach (['items', 'hydra:member', 'member'] as $key) {
            if (! isset($data[$key]) || ! is_array($data[$key])) {
                continue;
            }
            $normalized = $this->normalizeToListOfOrderArrays($data[$key]);
            if ($normalized !== []) {
                return $normalized;
            }
        }

        return [];
    }

    /**
     * @param mixed $raw
     *
     * @return list<array<string, mixed>>
     */
    private function normalizeToListOfOrderArrays(mixed $raw): array
    {
        if (! is_array($raw) || $raw === []) {
            return [];
        }
        $keys = array_keys($raw);
        $isList = $keys === range(0, count($raw) - 1);
        if ($isList) {
            $out = [];
            foreach ($raw as $item) {
                if (is_array($item)) {
                    $out[] = $item;
                }
            }

            return $out;
        }

        return [$raw];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findShipmentsByClientExtId(string $clientExtId): array
    {
        $query = $this->buildListQuery($clientExtId, 'clientExtId', 25, 1);
        $result = $this->request('GET', '/api/delivery-services/requests?' . $query, null);

        return $this->parseShipmentsFromDeliveryRequestsResponseBody($result['ok'] ? $result['data'] : null);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findShipmentsByExtId(string $extId): array
    {
        return $this->findShipmentsByClientExtId($extId);
    }

    /**
     * Delivery requests linked to a backend order id (same filter as Shopify admin extensions).
     *
     * @return list<array<string, mixed>>
     */
    public function findShipmentsByBackendOrderId(int $backendOrderId): array
    {
        if ($backendOrderId <= 0) {
            return [];
        }
        $query = $this->buildListQuery((string) $backendOrderId, 'order', 25, 1);
        $result = $this->request('GET', '/api/delivery-services/requests?' . $query, null);

        return $this->parseShipmentsFromDeliveryRequestsResponseBody($result['ok'] ? $result['data'] : null);
    }

    /**
     * Resolve shipments like Shopify getShipmentsForOrder: by backend order id, then by clientExtId candidates, then embedded deliveryRequest on the order entity.
     *
     * @param array<string, mixed>|null $backendOrder First order from Octava when found by extId lookup
     * @param list<string>               $extIdCandidates Tried in order when the by-order list is empty
     *
     * @return list<array<string, mixed>>
     */
    public function findShipmentsForConnector(?array $backendOrder, array $extIdCandidates): array
    {
        $backendNumericId = null;
        if (is_array($backendOrder) && isset($backendOrder['id'])) {
            $idRaw = $backendOrder['id'];
            if (is_int($idRaw)) {
                $backendNumericId = $idRaw > 0 ? $idRaw : null;
            } elseif (is_numeric($idRaw)) {
                $i = (int) $idRaw;
                $backendNumericId = $i > 0 ? $i : null;
            }
        }

        if ($backendNumericId !== null) {
            $byOrder = $this->findShipmentsByBackendOrderId($backendNumericId);
            if ($byOrder !== []) {
                return $byOrder;
            }
        }

        foreach ($extIdCandidates as $ext) {
            $t = trim((string) $ext);
            if ($t === '') {
                continue;
            }
            $byExt = $this->findShipmentsByClientExtId($t);
            if ($byExt !== []) {
                return $byExt;
            }
        }

        $embedded = $this->extractEmbeddedDeliveryRequestFromOrder($backendOrder);
        if ($embedded !== null) {
            return [$embedded];
        }

        return [];
    }

    /**
     * GET /api/delivery-services/requests/{id}
     *
     * @return array<string, mixed>|null
     */
    public function getShipmentById(int $shipmentId): ?array
    {
        if ($shipmentId <= 0) {
            return null;
        }
        $result = $this->request('GET', '/api/delivery-services/requests/' . $shipmentId, null);
        if (! $result['ok'] || ! is_array($result['data'])) {
            return null;
        }

        return $result['data'];
    }

    /**
     * PATCH /api/delivery-services/requests/{id}
     *
     * @param array<string, mixed> $jsonBody
     *
     * @return array{ok: bool, data: array<string, mixed>|null, message?: string}
     */
    public function patchShipment(int $shipmentId, array $jsonBody): array
    {
        if ($shipmentId <= 0) {
            return ['ok' => false, 'data' => null, 'message' => 'Invalid shipment id.'];
        }
        $result = $this->request('PATCH', '/api/delivery-services/requests/' . $shipmentId, $jsonBody);
        if ($result['ok'] && is_array($result['data'])) {
            return ['ok' => true, 'data' => $result['data']];
        }
        $msg = 'Patch failed.';
        if (is_array($result['data'])) {
            $msg = PluginLog::userMessageFromApiJson($result['data'], $msg);
        } elseif ($result['raw'] !== '') {
            $msg = mb_substr($result['raw'], 0, 500);
        } else {
            $msg = sprintf('HTTP %d', $result['status']);
        }

        return ['ok' => false, 'data' => is_array($result['data']) ? $result['data'] : null, 'message' => $msg];
    }

    /**
     * PATCH shipment using HAL self href (relative or absolute), matching Shopify patchShipmentServicePoint.
     *
     * @param array<string, mixed> $payload Body keys e.g. servicePoint, deliveryService
     *
     * @return array{ok: bool, data: array<string, mixed>|null, message?: string}
     */
    public function patchShipmentAtHref(string $selfHref, array $payload): array
    {
        $href = trim($selfHref);
        if ($href === '') {
            return ['ok' => false, 'data' => null, 'message' => 'Missing shipment href.'];
        }
        $body = $payload;
        if (array_key_exists('deliveryService', $body)) {
            $body['state'] = 'pending_queued';
        }
        if (array_key_exists('eav', $body)) {
            $body['state'] = 'pending_queued';
        }
        $path = $this->normalizeShipmentSelfHrefToRequestPath($href);
        $result = $this->request('PATCH', $path, $body);
        if ($result['ok'] && is_array($result['data'])) {
            return ['ok' => true, 'data' => $result['data']];
        }
        $msg = 'Patch failed.';
        if (is_array($result['data'])) {
            $msg = PluginLog::userMessageFromApiJson($result['data'], $msg);
        } elseif ($result['raw'] !== '') {
            $msg = mb_substr($result['raw'], 0, 500);
        } else {
            $msg = sprintf('HTTP %d', $result['status']);
        }

        return ['ok' => false, 'data' => is_array($result['data']) ? $result['data'] : null, 'message' => $msg];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total_pages: int, request?: array<string, mixed>, response?: array<string, mixed>}
     */
    public function fetchServicePoints(array $params): array
    {
        $localityId = isset($params['localityId']) && is_int($params['localityId']) ? $params['localityId'] : null;
        $deliveryServiceId = isset($params['deliveryServiceId']) && is_int($params['deliveryServiceId']) ? $params['deliveryServiceId'] : null;
        $servicePointType = isset($params['servicePointType']) && is_string($params['servicePointType']) ? trim($params['servicePointType']) : '';
        $search = isset($params['search']) && is_string($params['search']) ? trim($params['search']) : '';
        $page = isset($params['page']) && is_int($params['page']) && $params['page'] > 0 ? $params['page'] : 1;
        $perPageRaw = isset($params['perPage']) && is_int($params['perPage']) ? $params['perPage'] : 250;
        $perPage = max(1, min(500, $perPageRaw));
        $lat = isset($params['lat']) && is_numeric($params['lat']) ? (float) $params['lat'] : null;
        $lng = isset($params['lng']) && is_numeric($params['lng']) ? (float) $params['lng'] : null;
        $sort = isset($params['sort']) && is_string($params['sort']) ? trim($params['sort']) : '';
        $browserGeolocationEnabled = ($params['browserGeolocationEnabled'] ?? null) === true;

        $parts = [];
        $idx = 0;
        if ($deliveryServiceId !== null) {
            $parts[] = 'filter[' . $idx . '][type]=eq';
            $parts[] = 'filter[' . $idx . '][field]=deliveryService';
            $parts[] = 'filter[' . $idx . '][value]=' . rawurlencode((string) $deliveryServiceId);
            ++$idx;
        }
        if ($localityId !== null) {
            $parts[] = 'filter[' . $idx . '][type]=eq';
            $parts[] = 'filter[' . $idx . '][field]=locality';
            $parts[] = 'filter[' . $idx . '][value]=' . rawurlencode((string) $localityId);
            ++$idx;
        }
        if ($servicePointType !== '') {
            $parts[] = 'filter[' . $idx . '][type]=eq';
            $parts[] = 'filter[' . $idx . '][field]=type';
            $parts[] = 'filter[' . $idx . '][value]=' . rawurlencode($servicePointType);
            ++$idx;
        }
        $parts[] = 'filter[' . $idx . '][type]=eq';
        $parts[] = 'filter[' . $idx . '][field]=state';
        $parts[] = 'filter[' . $idx . '][value]=active';
        $parts[] = 'page=' . $page;
        $parts[] = 'perPage=' . $perPage;
        if ($search !== '') {
            $term = str_ends_with($search, ':*') ? $search : $search . ':*';
            $parts[] = 'search=' . rawurlencode($term);
        }
        if ($lat !== null && $lng !== null) {
            $parts[] = 'lat=' . rawurlencode(number_format($lat, 5, '.', ''));
            $parts[] = 'lng=' . rawurlencode(number_format($lng, 5, '.', ''));
            if ($sort !== '') {
                $parts[] = 'sort=' . rawurlencode($sort);
            }
            if ($browserGeolocationEnabled) {
                $parts[] = 'browserGeolocationEnabled=true';
            }
        }
        $query = implode('&', $parts);
        $result = $this->request('GET', '/api/delivery-services/service-points?' . $query, null);
        $log = [
            'request' => is_array($result['request'] ?? null) ? $result['request'] : [],
            'response' => PluginLog::responseFromFetched(
                (int) ($result['status'] ?? 0),
                is_array($result['response_headers'] ?? null) ? $result['response_headers'] : [],
                (string) ($result['raw'] ?? ''),
                is_array($result['data'] ?? null) ? $result['data'] : null
            )['response'],
        ];
        if (! $result['ok'] || ! is_array($result['data'])) {
            return ['items' => [], 'total_pages' => 1] + $log;
        }
        $data = $result['data'];
        $embedded = $data['_embedded'] ?? null;
        $items = [];
        if (is_array($embedded) && isset($embedded['servicePoints']) && is_array($embedded['servicePoints'])) {
            foreach ($embedded['servicePoints'] as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }
        }
        $items = array_slice($items, 0, $perPage);
        $totalPages = 1;
        if (isset($data['page_count']) && is_numeric($data['page_count'])) {
            $totalPages = max(1, (int) $data['page_count']);
        }

        return ['items' => $items, 'total_pages' => $totalPages] + $log;
    }

    /**
     * GET /api/delivery-services (paginated carrier list for admin UI).
     *
     * @return array{items: list<array<string, mixed>>, total_pages: int}
     */
    public function fetchDeliveryServicesPage(string $search, int $page): array
    {
        $page = max(1, $page);
        $trimSearch = trim($search);
        $perPage = ($trimSearch === '' && $page === 1)
            ? (int) apply_filters('octavawms_delivery_services_initial_per_page', 10)
            : (int) apply_filters('octavawms_delivery_services_per_page', 25);
        if ($perPage < 1) {
            $perPage = 1;
        }
        if ($perPage > 100) {
            $perPage = 100;
        }
        $parts = [
            'page=' . $page,
            'perPage=' . $perPage,
        ];
        if ($trimSearch !== '') {
            $parts[] = 'filter[0][type]=ilike';
            $parts[] = 'filter[0][field]=name';
            $parts[] = 'filter[0][value]=' . rawurlencode('%' . $trimSearch . '%');
        }
        $query = implode('&', $parts);
        $result = $this->request('GET', '/api/delivery-services?' . $query, null);
        if (! $result['ok'] || ! is_array($result['data'])) {
            return ['items' => [], 'total_pages' => 1];
        }
        $data = $result['data'];
        $embedded = $data['_embedded'] ?? null;
        $items = [];
        if (is_array($embedded) && isset($embedded['delivery_services']) && is_array($embedded['delivery_services'])) {
            foreach ($embedded['delivery_services'] as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }
        }
        $totalPages = 1;
        if (isset($data['page_count']) && is_numeric($data['page_count'])) {
            $totalPages = max(1, (int) $data['page_count']);
        }

        return ['items' => $items, 'total_pages' => $totalPages];
    }

    /**
     * GET /api/delivery-services/senders — active senders for the authenticated account.
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   has_more: bool,
     *   ok: bool,
     *   message: string,
     *   diagnostics: array<string, mixed>
     * }
     */
    public function fetchActiveSendersPreview(int $limit = 2): array
    {
        $limit = max(1, min(10, $limit));
        $parts = [
            'page=1',
            'perPage=' . $limit,
            'filter[0][type]=eq',
            'filter[0][field]=state',
            'filter[0][value]=active',
        ];
        $query = implode('&', $parts);
        $path = '/api/delivery-services/senders?' . $query;
        $result = $this->request('GET', $path, null);
        $diagnostics = $this->sendersPreviewDiagnostics($path, $result);

        if (! ($result['ok'] ?? false) || ! is_array($result['data'] ?? null)) {
            return [
                'items' => [],
                'has_more' => false,
                'ok' => false,
                'message' => PluginLog::userMessageFromApiJson(
                    is_array($result['data'] ?? null) ? $result['data'] : null,
                    (string) ($result['raw'] ?? 'Senders request failed.')
                ),
                'diagnostics' => $diagnostics + ['parse_note' => 'senders_api_error'],
            ];
        }

        $parsed = $this->parseSendersCollection($result['data'], $limit);
        if ($parsed['items'] === []) {
            $diagnostics['embedded_keys'] = $parsed['embedded_keys'];
            $diagnostics['parse_note'] = 'no_active_senders_in_response';
            $diagnostics['total_items'] = $parsed['total_items'];
            $diagnostics['page_count'] = $parsed['page_count'];
        }

        return [
            'items' => $parsed['items'],
            'has_more' => $parsed['has_more'],
            'ok' => true,
            'message' => '',
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private function sendersPreviewDiagnostics(string $path, array $result): array
    {
        return [
            'api_base_url' => $this->getBaseUrl(),
            'bearer_token_configured' => Options::getApiKey() !== '',
            'request' => is_array($result['request'] ?? null) ? $result['request'] : PluginLog::requestFromOutbound(
                'GET',
                rtrim($this->getBaseUrl(), '/') . $path,
                ['Accept' => 'application/json'],
                null
            ),
        ] + PluginLog::responseFromFetched(
            (int) ($result['status'] ?? 0),
            is_array($result['response_headers'] ?? null) ? $result['response_headers'] : [],
            (string) ($result['raw'] ?? ''),
            is_array($result['data'] ?? null) ? $result['data'] : null
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{
     *   items: list<array<string, mixed>>,
     *   has_more: bool,
     *   embedded_keys: list<string>,
     *   total_items: int|null,
     *   page_count: int|null
     * }
     */
    private function parseSendersCollection(array $data, int $limit): array
    {
        $embedded = $data['_embedded'] ?? null;
        $items = [];
        $embeddedKeys = is_array($embedded) ? array_values(array_map('strval', array_keys($embedded))) : [];
        if (is_array($embedded)) {
            foreach (['senders', 'sender'] as $key) {
                if (! isset($embedded[$key]) || ! is_array($embedded[$key])) {
                    continue;
                }
                foreach ($embedded[$key] as $row) {
                    if (is_array($row)) {
                        $items[] = $row;
                    }
                }
                break;
            }
        }

        $pageCount = isset($data['page_count']) && is_numeric($data['page_count']) ? (int) $data['page_count'] : null;
        $totalItems = isset($data['total_items']) && is_numeric($data['total_items']) ? (int) $data['total_items'] : null;
        $hasMore = count($items) >= $limit
            || ($pageCount !== null && $pageCount > 1)
            || ($totalItems !== null && $totalItems > count($items));

        return [
            'items' => $items,
            'has_more' => $hasMore,
            'embedded_keys' => $embeddedKeys,
            'total_items' => $totalItems,
            'page_count' => $pageCount,
        ];
    }

    /**
     * GET /api/locations/localities (admin search, aligned with Shopify app.edit-shipment).
     *
     * @return array{items: list<array<string, mixed>>, total_pages: int}
     */
    public function fetchLocalitiesPage(string $search, int $page, ?string $exactId = null): array
    {
        $page = max(1, $page);
        $parts = [
            'per_page=25',
            'page=' . $page,
        ];
        $trimSearch = trim($search);
        if ($exactId !== null && trim($exactId) !== '') {
            $idTrim = trim($exactId);
            $parts[] = 'filter[0][type]=eq';
            $parts[] = 'filter[0][field]=id';
            $parts[] = 'filter[0][value]=' . rawurlencode($idTrim);
        } else {
            $parts[] = 'filter[0][type]=eq';
            $parts[] = 'filter[0][field]=state';
            $parts[] = 'filter[0][value]=active';
            if ($trimSearch !== '') {
                $term = str_ends_with($trimSearch, ':*') ? $trimSearch : $trimSearch . ':*';
                $parts[] = 'search=' . rawurlencode($term);
            }
        }
        $query = implode('&', $parts);
        $result = $this->request('GET', '/api/locations/localities?' . $query, null);
        if (! $result['ok'] || ! is_array($result['data'])) {
            return ['items' => [], 'total_pages' => 1];
        }

        return $this->parseLocalitiesCollection($result['data']);
    }

    /**
     * GET /api/locations/localities filtered by active postcode.
     *
     * @return array{items: list<array<string, mixed>>, total_pages: int}
     */
    public function fetchLocalitiesByPostcode(string $postcode, int $page = 1): array
    {
        $postcode = trim($postcode);
        if ($postcode === '') {
            return ['items' => [], 'total_pages' => 1];
        }

        $page = max(1, $page);
        $parts = [
            'per_page=25',
            'page=' . $page,
            'filter[0][type]=eq',
            'filter[0][field]=state',
            'filter[0][value]=active',
            'filter[1][type]=eq',
            'filter[1][field]=postcode',
            'filter[1][value]=' . rawurlencode($postcode),
        ];
        $query = implode('&', $parts);
        $result = $this->request('GET', '/api/locations/localities?' . $query, null);
        if (! $result['ok'] || ! is_array($result['data'])) {
            return ['items' => [], 'total_pages' => 1];
        }

        return $this->parseLocalitiesCollection($result['data']);
    }

    /**
     * GET /api/delivery-services/postcodes filtered by external postcode id.
     *
     * @return array{items: list<array<string, mixed>>, total_pages: int}
     */
    public function fetchDeliveryServicePostcodesByExtId(string $extId, int $page = 1): array
    {
        $extId = trim($extId);
        if ($extId === '') {
            return ['items' => [], 'total_pages' => 1];
        }

        $page = max(1, $page);
        $parts = [
            'perPage=25',
            'page=' . $page,
            'filter[0][type]=eq',
            'filter[0][field]=extId',
            'filter[0][value]=' . rawurlencode($extId),
        ];
        $query = implode('&', $parts);
        $result = $this->request('GET', '/api/delivery-services/postcodes?' . $query, null);
        if (! $result['ok'] || ! is_array($result['data'])) {
            return ['items' => [], 'total_pages' => 1];
        }

        return $this->parseDeliveryServicePostcodesCollection($result['data']);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{items: list<array<string, mixed>>, total_pages: int}
     */
    private function parseDeliveryServicePostcodesCollection(array $data): array
    {
        $embedded = $data['_embedded'] ?? null;
        $items = [];
        if (is_array($embedded)) {
            foreach (['postcodes', 'deliveryServicePostcodes', 'delivery_service_postcodes'] as $key) {
                if (isset($embedded[$key]) && is_array($embedded[$key])) {
                    foreach ($embedded[$key] as $row) {
                        if (is_array($row)) {
                            $items[] = $row;
                        }
                    }

                    break;
                }
            }
            if ($items === []) {
                foreach ($embedded as $rows) {
                    if (! is_array($rows)) {
                        continue;
                    }
                    foreach ($rows as $row) {
                        if (is_array($row)) {
                            $items[] = $row;
                        }
                    }
                    if ($items !== []) {
                        break;
                    }
                }
            }
        }
        $totalPages = 1;
        if (isset($data['page_count']) && is_numeric($data['page_count'])) {
            $totalPages = max(1, (int) $data['page_count']);
        }

        return ['items' => $items, 'total_pages' => $totalPages];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{items: list<array<string, mixed>>, total_pages: int}
     */
    private function parseLocalitiesCollection(array $data): array
    {
        $embedded = $data['_embedded'] ?? null;
        $items = [];
        if (is_array($embedded) && isset($embedded['localities']) && is_array($embedded['localities'])) {
            foreach ($embedded['localities'] as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }
        }
        $totalPages = 1;
        if (isset($data['page_count']) && is_numeric($data['page_count'])) {
            $totalPages = max(1, (int) $data['page_count']);
        }

        return ['items' => $items, 'total_pages' => $totalPages];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchPlacesForDeliveryRequest(int $deliveryRequestId): array
    {
        if ($deliveryRequestId <= 0) {
            return [];
        }
        $query = http_build_query([
            'filter[0][type]' => 'eq',
            'filter[0][field]' => 'deliveryRequest',
            'filter[0][value]' => $deliveryRequestId,
            'order-by[0][type]' => 'field',
            'order-by[0][field]' => 'priority',
            'order-by[0][direction]' => 'desc',
            'per_page' => 250,
        ], '', '&', PHP_QUERY_RFC3986);
        $result = $this->request('GET', '/api/delivery-services/places?' . $query, null);
        if (! $result['ok'] || ! is_array($result['data'])) {
            return [];
        }
        $embedded = $result['data']['_embedded'] ?? null;
        if (! is_array($embedded) || ! isset($embedded['places']) || ! is_array($embedded['places'])) {
            return [];
        }
        $out = [];
        foreach ($embedded['places'] as $p) {
            if (is_array($p)) {
                $out[] = $p;
            }
        }

        return $this->dedupePlacesByIdPreserveOrder($out);
    }

    /**
     * HAL collections may list the same place more than once; keep the first occurrence (API order, e.g. priority sort).
     *
     * @param list<array<string, mixed>> $places
     * @return list<array<string, mixed>>
     */
    private function dedupePlacesByIdPreserveOrder(array $places): array
    {
        $seen = [];
        $out = [];
        foreach ($places as $p) {
            if (! is_array($p)) {
                continue;
            }
            $key = self::placeHalIdentityKey($p);
            if ($key === null) {
                $out[] = $p;

                continue;
            }
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $p;
        }

        return $out;
    }

    /**
     * Stable string key for HAL place identity, or null when the payload has no usable id (pass through to caller list).
     */
    private static function placeHalIdentityKey(array $p): ?string
    {
        if (! array_key_exists('id', $p)) {
            return null;
        }
        $raw = $p['id'];
        if (is_int($raw) || is_float($raw)) {
            $n = (int) $raw;

            return $n > 0 ? (string) $n : null;
        }
        if (is_string($raw)) {
            $t = trim($raw);
            if ($t === '' || $t === '0') {
                return null;
            }
            if (ctype_digit($t)) {
                return (string) (int) $t;
            }

            return $t;
        }

        return null;
    }

    /**
     * @return array{ok: bool, data: array<string, mixed>|null, message?: string}
     */
    public function addPlace(int $deliveryRequestId): array
    {
        if ($deliveryRequestId <= 0) {
            return ['ok' => false, 'data' => null, 'message' => 'Invalid delivery request.'];
        }
        $payload = [
            'deliveryRequest' => $deliveryRequestId,
            'type' => 'simple',
            'weight' => 0,
            'dimensions' => ['x' => 0, 'y' => 0, 'z' => 0],
        ];
        $result = $this->request('POST', '/api/delivery-services/places', $payload);
        if ($result['ok'] && is_array($result['data'])) {
            return ['ok' => true, 'data' => $result['data']];
        }
        $msg = 'Add place failed.';
        if (is_array($result['data'])) {
            $msg = PluginLog::userMessageFromApiJson($result['data'], $msg);
        } elseif ($result['raw'] !== '') {
            $msg = mb_substr($result['raw'], 0, 500);
        } else {
            $msg = sprintf('HTTP %d', $result['status']);
        }

        return ['ok' => false, 'data' => is_array($result['data']) ? $result['data'] : null, 'message' => $msg];
    }

    /**
     * @param array<string, mixed> $updates
     *
     * @return array{ok: bool, data: array<string, mixed>|null, message?: string}
     */
    public function updatePlace(int $placeId, array $updates): array
    {
        if ($placeId <= 0) {
            return ['ok' => false, 'data' => null, 'message' => 'Invalid place id.'];
        }
        $result = $this->request('PATCH', '/api/delivery-services/places/' . $placeId, $updates);
        if ($result['ok'] && is_array($result['data'])) {
            return ['ok' => true, 'data' => $result['data']];
        }
        $msg = 'Update place failed.';
        if (is_array($result['data'])) {
            $msg = PluginLog::userMessageFromApiJson($result['data'], $msg);
        } elseif ($result['raw'] !== '') {
            $msg = mb_substr($result['raw'], 0, 500);
        } else {
            $msg = sprintf('HTTP %d', $result['status']);
        }

        return ['ok' => false, 'data' => is_array($result['data']) ? $result['data'] : null, 'message' => $msg];
    }

    /**
     * @return array{ok: bool, data: array<string, mixed>|null, message?: string}
     */
    public function deletePlace(int $placeId): array
    {
        return $this->updatePlace($placeId, ['state' => 'deleted']);
    }

    /**
     * @param array<string, mixed>|null $data Response body of GET /api/delivery-services/requests
     *
     * @return list<array<string, mixed>>
     */
    private function parseShipmentsFromDeliveryRequestsResponseBody(mixed $data): array
    {
        if (! is_array($data)) {
            return [];
        }
        $embedded = $data['_embedded'] ?? null;
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
     * @param array<string, mixed>|null $backendOrder
     *
     * @return array<string, mixed>|null
     */
    private function extractEmbeddedDeliveryRequestFromOrder(?array $backendOrder): ?array
    {
        if (! is_array($backendOrder)) {
            return null;
        }
        $embedded = $backendOrder['_embedded'] ?? null;
        if (! is_array($embedded)) {
            return null;
        }
        $dr = $embedded['deliveryRequest'] ?? null;
        if (is_array($dr) && isset($dr['id'])) {
            return $dr;
        }

        return null;
    }

    private function normalizeShipmentSelfHrefToRequestPath(string $href): string
    {
        if (preg_match('#^https?://#i', $href)) {
            $base = rtrim($this->getBaseUrl(), '/');
            if (str_starts_with($href, $base)) {
                $rest = substr($href, strlen($base));

                return '/' . ltrim((string) $rest, '/');
            }

            $parts = wp_parse_url($href);
            if (is_array($parts) && isset($parts['path']) && is_string($parts['path'])) {
                $path = $parts['path'];
                $query = isset($parts['query']) && is_string($parts['query']) ? '?' . $parts['query'] : '';

                return $path . $query;
            }
        }

        return '/' . ltrim($href, '/');
    }

    /**
     * @return array{ok: true, data?: mixed}|array{ok: false, message: string, status?: int, raw_excerpt?: string, data?: mixed}
     */
    public function importOrder(string $extId, int $sourceId): array
    {
        if ($sourceId <= 0) {
            return ['ok' => false, 'message' => 'OctavaWMS source is not configured. Connect the plugin under WooCommerce → Settings → Integrations.'];
        }

        $importAsync = Options::isImportAsyncEnabled();
        $payload = [
            'extId' => '',
            'sourceData' => [
                'async' => $importAsync,
                'asyncMode' => ['Orderadmin\\Products\\Entity\\AbstractOrder' => $importAsync],
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
     * Request one merged labels file through the backend bulk-label import service.
     *
     * @param list<int|string> $shipmentIds Delivery request ids in print order.
     *
     * @return array{ok: bool, import_id: int|null, file_url: string|null, state: string|null, message?: string, status?: int, data?: mixed}
     */
    public function importBulkLabels(array $shipmentIds, int $senderId, bool $sync = true): array
    {
        $shipments = [];
        foreach ($shipmentIds as $shipmentId) {
            if (! is_numeric($shipmentId)) {
                continue;
            }
            $id = (int) $shipmentId;
            if ($id <= 0) {
                continue;
            }
            $shipments[] = $id;
        }

        if ($shipments === []) {
            return ['ok' => false, 'import_id' => null, 'file_url' => null, 'state' => null, 'message' => 'No printable labels selected.'];
        }

        if ($senderId <= 0) {
            return ['ok' => false, 'import_id' => null, 'file_url' => null, 'state' => null, 'message' => 'Sender is required for bulk label printing.'];
        }

        $sourceData = [
            'sender' => $senderId,
            'shipments' => $shipments,
            'asyncMode' => [
                'Orderadmin\\DeliveryServices\\Service\\ImportLabelsService' => true,
            ],
        ];
        if ($sync) {
            $sourceData['async'] = false;
        }

        $payload = [
            'handler' => 'delivery-services',
            'extId' => null,
            'sourceData' => $sourceData,
        ];

        $result = $this->request('POST', '/api/integrations/import', $payload);
        if ($result['ok'] && is_array($result['data'])) {
            return [
                'ok' => true,
                'import_id' => self::extractImportId($result['data']),
                'file_url' => self::extractImportFileUrl($result['data']),
                'state' => self::extractImportState($result['data']),
                'data' => $result['data'],
            ];
        }

        $msg = 'Bulk label import failed.';
        if (is_array($result['data'])) {
            $msg = PluginLog::userMessageFromApiJson($result['data'], $msg);
        } elseif ($result['raw'] !== '') {
            $msg = mb_substr($result['raw'], 0, 500);
        } else {
            $msg = sprintf('HTTP %d', $result['status']);
        }

        return [
            'ok' => false,
            'import_id' => null,
            'file_url' => null,
            'state' => null,
            'message' => $msg,
            'status' => $result['status'],
            'data' => $result['data'],
        ];
    }

    /**
     * @return array{ok: bool, import_id: int|null, file_url: string|null, state: string|null, message?: string, status?: int, data?: mixed}
     */
    public function getImportStatus(int $importId): array
    {
        if ($importId <= 0) {
            return ['ok' => false, 'import_id' => null, 'file_url' => null, 'state' => null, 'message' => 'Invalid import id.'];
        }

        $result = $this->request('GET', '/api/integrations/import/' . $importId, null);
        if ($result['ok'] && is_array($result['data'])) {
            $state = self::extractImportState($result['data']);
            $message = null;
            if ($state === 'error') {
                $message = self::extractImportErrorMessage($result['data']) ?? 'Import processing failed.';
            }

            $out = [
                'ok' => true,
                'import_id' => self::extractImportId($result['data']) ?? $importId,
                'file_url' => self::extractImportFileUrl($result['data']),
                'state' => $state,
                'data' => $result['data'],
            ];
            if ($message !== null) {
                $out['message'] = $message;
            }

            return $out;
        }

        $msg = 'Could not fetch import status.';
        if (is_array($result['data'])) {
            $msg = PluginLog::userMessageFromApiJson($result['data'], $msg);
        } elseif ($result['raw'] !== '') {
            $msg = mb_substr($result['raw'], 0, 500);
        } else {
            $msg = sprintf('HTTP %d', $result['status']);
        }

        return [
            'ok' => false,
            'import_id' => $importId,
            'file_url' => null,
            'state' => null,
            'message' => $msg,
            'status' => $result['status'],
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
     * @param array<string, mixed> $data
     */
    private static function extractImportId(array $data): ?int
    {
        foreach ([['id'], ['importId'], ['import', 'id']] as $path) {
            $value = self::valueAtPath($data, $path);
            if (is_numeric($value)) {
                $id = (int) $value;

                return $id > 0 ? $id : null;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractImportState(array $data): ?string
    {
        foreach (['state', 'status'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $state = trim((string) $data[$key]);
                if ($state !== '') {
                    return $state;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractImportFileUrl(array $data): ?string
    {
        foreach ([['fileUrl'], ['file', 'url'], ['_links', 'file', 'href'], ['loadResult', 'url']] as $path) {
            $value = self::valueAtPath($data, $path);
            if (is_scalar($value)) {
                $url = trim((string) $value);
                if ($url !== '') {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractImportErrorMessage(array $data): ?string
    {
        foreach ([['loadResult', 'error'], ['errorMessage'], ['error'], ['message']] as $path) {
            $value = self::valueAtPath($data, $path);
            if (is_scalar($value)) {
                $message = trim((string) $value);
                if ($message !== '') {
                    return $message;
                }
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $path
     */
    private static function valueAtPath(array $data, array $path): mixed
    {
        $value = $data;
        foreach ($path as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
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
     * Sender entity id from GET /api/delivery-services/requests/{id} (HAL / composite shapes).
     */
    public static function extractSenderIdFromDeliveryRequestDetail(?array $detail): ?int
    {
        if (! is_array($detail)) {
            return null;
        }
        $paths = [
            ['_embedded', 'sender'],
            ['sender'],
            ['_embedded', 'order', 'sender'],
        ];
        foreach ($paths as $path) {
            $v = $detail;
            foreach ($path as $seg) {
                if (! is_array($v) || ! array_key_exists($seg, $v)) {
                    $v = null;
                    break;
                }
                $v = $v[$seg];
            }
            if (! is_array($v) || ! isset($v['id']) || ! is_numeric($v['id'])) {
                continue;
            }
            $i = (int) $v['id'];

            return $i > 0 ? $i : null;
        }

        return null;
    }

    /**
     * Sender display name from GET /api/delivery-services/requests/{id} (HAL / composite shapes).
     */
    public static function extractSenderNameFromDeliveryRequestDetail(?array $detail): ?string
    {
        if (! is_array($detail)) {
            return null;
        }
        $paths = [
            ['_embedded', 'sender'],
            ['sender'],
            ['_embedded', 'order', 'sender'],
        ];
        foreach ($paths as $path) {
            $v = $detail;
            foreach ($path as $seg) {
                if (! is_array($v) || ! array_key_exists($seg, $v)) {
                    $v = null;
                    break;
                }
                $v = $v[$seg];
            }
            if (! is_array($v) || ! isset($v['name']) || ! is_string($v['name'])) {
                continue;
            }
            $n = trim($v['name']);

            return $n !== '' ? $n : null;
        }

        return null;
    }

    /**
     * Required `name` for POST /api/delivery-services/preprocessing-queue: sender name, else site title, else host.
     */
    public static function preprocessingQueueDisplayName(?array $deliveryRequestDetail): string
    {
        $fromSender = self::extractSenderNameFromDeliveryRequestDetail($deliveryRequestDetail);
        if ($fromSender !== null && $fromSender !== '') {
            return $fromSender;
        }
        $blog = trim((string) get_bloginfo('name'));
        if ($blog !== '') {
            return $blog;
        }
        $host = wp_parse_url((string) home_url(), PHP_URL_HOST);
        if (is_string($host)) {
            $host = trim($host);
        }
        if (is_string($host) && $host !== '') {
            return $host;
        }

        return 'WooCommerce';
    }

    /**
     * Ensure a preprocessing queue exists for label generation (sender-scoped on the backend).
     *
     * POST /api/delivery-services/preprocessing-queue with **name** and optional **sender** only.
     * Do not send deliveryRequest here — that relation belongs on {@see createOrUpdatePreprocessingTask()} only.
     *
     * @param int $deliveryRequestId Valid shipment id from the caller (used only for guards/logging context; not POSTed).
     * @param string|null $name Queue display name when known; omit to derive via {@see self::preprocessingQueueDisplayName()}.
     * @return array{ok: bool, message: string, queue_id: int|null}
     */
    public function createProcessingQueueForSender(int $deliveryRequestId, ?int $senderId, bool $retried = false, ?string $name = null): array
    {
        if ($deliveryRequestId <= 0) {
            return ['ok' => false, 'message' => 'Invalid delivery request id.', 'queue_id' => null];
        }

        $candidate = $name !== null ? trim((string) $name) : '';
        $resolvedName = $candidate !== '' ? $candidate : self::preprocessingQueueDisplayName(null);

        $body = [
            'name' => $resolvedName,
        ];
        if ($senderId !== null && $senderId > 0) {
            $body['sender'] = $senderId;
        }

        $path = '/api/delivery-services/preprocessing-queue';

        $result = $this->request('POST', $path, $body, $retried);
        if (! $result['ok']) {
            $msg = PluginLog::userMessageFromApiJson(is_array($result['data']) ? $result['data'] : null, 'Could not create processing queue for this shipment.');

            return ['ok' => false, 'message' => $msg, 'queue_id' => null];
        }

        $queueId = null;
        if (is_array($result['data'])) {
            $queueId = self::extractQueueIdFromCreateProcessingQueueResponse($result['data']);
        }

        return ['ok' => true, 'message' => '', 'queue_id' => $queueId];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function extractQueueIdFromCreateProcessingQueueResponse(array $data): ?int
    {
        foreach ([['id'], ['queue', 'id'], ['queue', 'queue', 'id']] as $path) {
            $v = $data;
            foreach ($path as $seg) {
                if (! is_array($v) || ! array_key_exists($seg, $v)) {
                    $v = null;
                    break;
                }
                $v = $v[$seg];
            }
            if (is_numeric($v)) {
                $i = (int) $v;

                return $i > 0 ? $i : null;
            }
        }

        return null;
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

            $errBody = (string) wp_remote_retrieve_body($response);

            return [
                'ok' => false,
                'pdf' => null,
                'content_type' => '',
                'task_id' => $taskId,
                'message' => self::httpStatusWithApiDetailMessage($status, $errBody),
            ];
        }

        $contentType = self::lowercaseContentTypeFromWpResponse($response);
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
        $contentType = self::lowercaseContentTypeFromWpResponse($response);
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

    /**
     * @param array<string, mixed> $response Success value from wp_remote_request()
     */
    private static function lowercaseContentTypeFromWpResponse(array $response): string
    {
        $flat = PluginLog::flattenWpResponseHeaders(wp_remote_retrieve_headers($response));
        foreach ($flat as $name => $value) {
            if (strtolower((string) $name) === 'content-type') {
                return strtolower((string) $value);
            }
        }

        return '';
    }

    /**
     * User-visible text for failed HTTP responses: status plus problem+json {@code detail} / {@code title} / etc.
     */
    private static function httpStatusWithApiDetailMessage(int $httpStatus, string $rawBody): string
    {
        $decoded = $rawBody !== '' ? json_decode($rawBody, true) : null;
        $data = is_array($decoded) ? $decoded : null;
        if ($data !== null) {
            $api = trim(PluginLog::userMessageFromApiJson($data, ''));
            if ($api !== '') {
                return sprintf('HTTP %d: %s', $httpStatus, $api);
            }
        }
        $t = trim($rawBody);
        if ($t !== '' && $data === null) {
            return sprintf('HTTP %d: %s', $httpStatus, PluginLog::truncate($t, 500));
        }

        return sprintf('HTTP %d', $httpStatus);
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

    /**
     * GET /api/integrations/sources/{id}
     *
     * @return array<string, mixed>|null
     */
    public function getIntegrationSource(int $sourceId): ?array
    {
        if ($sourceId <= 0) {
            return null;
        }
        $result = $this->request('GET', 'api/integrations/sources/' . $sourceId, null);
        if (! $result['ok'] || ! is_array($result['data'])) {
            return null;
        }

        return $result['data'];
    }

    /**
     * PATCH /api/integrations/sources/{id}
     *
     * @param array<string, mixed> $body
     *
     * @return array{ok: bool, status: int, data: mixed, raw: string, response_headers: array<string, string>, request?: array{method:string, url:string, headers:array<string,string>, body:array|string}}
     */
    public function patchIntegrationSource(int $sourceId, array $body): array
    {
        if ($sourceId <= 0) {
            return [
                'ok' => false,
                'status' => 400,
                'data' => null,
                'raw' => 'invalid source id',
                'response_headers' => [],
            ];
        }

        $result = $this->request('PATCH', 'api/integrations/sources/' . $sourceId, $body);
        if (! $result['ok']) {
            PluginLog::log(
                'warning',
                'integration_source',
                array_merge(
                    [
                        'note' => 'patch_integration_source_failed',
                        'source_id' => $sourceId,
                        'request' => $result['request'],
                    ],
                    PluginLog::responseFromFetched(
                        $result['status'],
                        $result['response_headers'],
                        (string) $result['raw'],
                        is_array($result['data']) ? $result['data'] : null
                    )
                )
            );
        }

        return $result;
    }

    /**
     * GET /api/delivery-services/integrations (paginated, optional name search).
     *
     * @return array{items: list<array<string, mixed>>, total_pages: int}
     */
    public function fetchDeliveryServiceIntegrationsPage(string $search, int $page): array
    {
        $page = max(1, $page);
        $perPage = 50;
        $parts = [
            'page=' . $page,
            'per_page=' . $perPage,
            'sort[id]=desc',
        ];
        $trimSearch = trim($search);
        if ($trimSearch !== '') {
            $parts[] = 'filter[0][type]=ilike';
            $parts[] = 'filter[0][field]=name';
            $parts[] = 'filter[0][value]=' . rawurlencode('%' . $trimSearch . '%');
        }
        $query = implode('&', $parts);
        $result = $this->request('GET', '/api/delivery-services/integrations?' . $query, null);
        if (! $result['ok'] || ! is_array($result['data'])) {
            return ['items' => [], 'total_pages' => 1];
        }
        $data = $result['data'];
        $embedded = $data['_embedded'] ?? null;
        $items = [];
        if (is_array($embedded)) {
            $list = $embedded['integration'] ?? $embedded['integrations'] ?? [];
            if (is_array($list)) {
                foreach ($list as $row) {
                    if (is_array($row)) {
                        $items[] = $row;
                    }
                }
            }
        }
        $totalPages = 1;
        if (isset($data['page_count']) && is_numeric($data['page_count'])) {
            $totalPages = max(1, (int) $data['page_count']);
        }

        return ['items' => $items, 'total_pages' => $totalPages];
    }

    /**
     * Active rates for a delivery service (carrier).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchRatesForDeliveryService(int $deliveryServiceId): array
    {
        if ($deliveryServiceId <= 0) {
            return [];
        }
        $parts = [
            'order-by[0][type]=field',
            'order-by[0][field]=id',
            'order-by[0][direction]=desc',
            'filter[0][type]=eq',
            'filter[0][field]=deliveryService',
            'filter[0][value]=' . rawurlencode((string) $deliveryServiceId),
            'filter[1][type]=eq',
            'filter[1][field]=state',
            'filter[1][value]=active',
            'per_page=100',
            'page=1',
        ];
        $query = implode('&', $parts);
        $result = $this->request('GET', '/api/delivery-services/rates?' . $query, null);
        if (! $result['ok'] || ! is_array($result['data'])) {
            return [];
        }
        $data = $result['data'];
        $embedded = $data['_embedded'] ?? null;
        $out = [];
        if (is_array($embedded)) {
            $list = $embedded['rate'] ?? $embedded['rates'] ?? [];
            if (is_array($list)) {
                foreach ($list as $row) {
                    if (is_array($row)) {
                        $out[] = $row;
                    }
                }
            }
        }

        return $out;
    }
}
