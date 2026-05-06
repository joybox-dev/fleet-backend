<?php

namespace App\Services\ErpNext;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Exception;

/**
 * ERPNext HTTP Client
 *
 * Low-level HTTP client for communicating with the ERPNext/Frappe REST API.
 * Handles authentication (token or session), retries, circuit-breaker,
 * and error normalization.
 *
 * FleetOps Design Rule: ERPNext is a financial mirror.
 * If ERPNext is down, FleetOps continues working normally.
 * All calls are async via queue jobs — this client is used by those jobs.
 */
class ErpNextClient
{
    private string $baseUrl;
    private string $authMethod;
    private array $config;
    private ?string $sessionCookie = null;

    public function __construct()
    {
        $this->config = config('erpnext');
        $this->baseUrl = rtrim($this->config['base_url'], '/');
        $this->authMethod = $this->config['auth_method'];
    }

    // ──────────────────────────────────────────────
    // Public API Methods
    // ──────────────────────────────────────────────

    /**
     * GET a single document from ERPNext.
     *
     * @param string $doctype  e.g. 'Customer', 'Employee', 'Sales Invoice'
     * @param string $name     Document name/ID
     * @param array  $fields   Optional list of fields to retrieve
     */
    public function getDocument(string $doctype, string $name, array $fields = []): ?array
    {
        $url = "/api/resource/{$doctype}/{$name}";
        $query = [];

        if (!empty($fields)) {
            $query['fields'] = json_encode($fields);
        }

        $response = $this->request('GET', $url, $query);

        return $response['data'] ?? null;
    }

    /**
     * List documents from ERPNext with filters.
     *
     * @param string $doctype
     * @param array  $filters  e.g. [['status', '=', 'Active']]
     * @param array  $fields   e.g. ['name', 'employee_name']
     * @param int    $limit
     * @param int    $offset
     */
    public function listDocuments(
        string $doctype,
        array $filters = [],
        array $fields = ['name'],
        int $limit = 20,
        int $offset = 0,
        ?string $orderBy = null
    ): array {
        $url = "/api/resource/{$doctype}";
        $query = [
            'filters' => json_encode($filters),
            'fields'  => json_encode($fields),
            'limit_page_length' => $limit,
            'limit_start' => $offset,
        ];

        if ($orderBy) {
            $query['order_by'] = $orderBy;
        }

        $response = $this->request('GET', $url, $query);

        return $response['data'] ?? [];
    }

    /**
     * Create a new document in ERPNext.
     *
     * @param string $doctype
     * @param array  $data     Document fields
     * @return array           Created document data (includes 'name')
     */
    public function createDocument(string $doctype, array $data): array
    {
        $url = "/api/resource/{$doctype}";

        $response = $this->request('POST', $url, [], $data);

        return $response['data'] ?? [];
    }

    /**
     * Update an existing document in ERPNext.
     *
     * @param string $doctype
     * @param string $name
     * @param array  $data     Fields to update
     */
    public function updateDocument(string $doctype, string $name, array $data): array
    {
        $url = "/api/resource/{$doctype}/{$name}";

        $response = $this->request('PUT', $url, [], $data);

        return $response['data'] ?? [];
    }

    /**
     * Call a whitelisted Frappe API method.
     *
     * @param string $method  e.g. 'frappe.client.get_count'
     * @param array  $params
     */
    public function callMethod(string $method, array $params = []): mixed
    {
        $url = "/api/method/{$method}";

        $response = $this->request('POST', $url, [], $params);

        return $response['message'] ?? $response['data'] ?? null;
    }

    /**
     * Check if ERPNext is reachable.
     */
    public function ping(): bool
    {
        try {
            $this->checkCircuitBreaker();
            $response = $this->buildHttpClient()
                ->get("{$this->baseUrl}/api/method/frappe.auth.get_logged_user");
            return $response->successful();
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Check if a document exists.
     */
    public function documentExists(string $doctype, string $name): bool
    {
        try {
            $doc = $this->getDocument($doctype, $name, ['name']);
            return $doc !== null;
        } catch (Exception $e) {
            return false;
        }
    }

    // ──────────────────────────────────────────────
    // Core HTTP Logic
    // ──────────────────────────────────────────────

    /**
     * Execute an HTTP request with retry and circuit-breaker logic.
     */
    private function request(string $method, string $url, array $query = [], array $body = []): array
    {
        $this->checkCircuitBreaker();

        $fullUrl = "{$this->baseUrl}{$url}";
        $lastException = null;
        $maxRetries = $this->config['sync']['max_retries'];
        $delay = $this->config['sync']['retry_delay_seconds'];
        $multiplier = $this->config['sync']['retry_backoff_multiplier'];

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $client = $this->buildHttpClient();

                /** @var Response $response */
                $response = match (strtoupper($method)) {
                    'GET'    => $client->get($fullUrl, $query),
                    'POST'   => $client->post($fullUrl, $body),
                    'PUT'    => $client->put($fullUrl, $body),
                    'DELETE' => $client->delete($fullUrl, $body),
                    default  => throw new Exception("Unsupported HTTP method: {$method}"),
                };

                if ($response->successful()) {
                    $this->recordSuccess();
                    return $response->json();
                }

                // ERPNext returns error details in the response body
                $errorBody = $response->json();
                $errorMsg = $errorBody['exc_type'] ?? $errorBody['_error_message'] ?? $response->body();

                // 409 = duplicate — don't retry, it's a business logic error
                if ($response->status() === 409) {
                    throw new ErpNextDuplicateException("Duplicate in ERPNext: {$errorMsg}");
                }

                // 403/401 = auth issue — don't retry
                if (in_array($response->status(), [401, 403])) {
                    $this->sessionCookie = null; // clear cached session
                    throw new ErpNextAuthException("ERPNext auth failed: {$errorMsg}");
                }

                // 4xx = client error — don't retry
                if ($response->status() >= 400 && $response->status() < 500) {
                    throw new ErpNextValidationException(
                        "ERPNext validation error [{$response->status()}]: {$errorMsg}",
                        $response->status()
                    );
                }

                // 5xx = server error — retry
                $lastException = new ErpNextServerException(
                    "ERPNext server error [{$response->status()}]: {$errorMsg}",
                    $response->status()
                );

            } catch (ErpNextDuplicateException | ErpNextAuthException | ErpNextValidationException $e) {
                // Non-retryable errors — throw immediately
                $this->recordFailure();
                Log::channel('erpnext')->error("ERPNext non-retryable error", [
                    'method' => $method,
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
                throw $e;
            } catch (Exception $e) {
                $lastException = $e;
            }

            // Log retry attempt
            Log::channel('erpnext')->warning("ERPNext request retry", [
                'attempt' => $attempt,
                'max' => $maxRetries,
                'method' => $method,
                'url' => $url,
                'delay' => $delay,
                'error' => $lastException?->getMessage(),
            ]);

            if ($attempt < $maxRetries) {
                sleep($delay);
                $delay *= $multiplier; // exponential backoff
            }
        }

        // All retries exhausted
        $this->recordFailure();

        Log::channel('erpnext')->error("ERPNext request failed after {$maxRetries} attempts", [
            'method' => $method,
            'url' => $url,
            'error' => $lastException?->getMessage(),
        ]);

        throw new ErpNextConnectionException(
            "ERPNext unreachable after {$maxRetries} attempts: " . ($lastException?->getMessage() ?? 'Unknown error')
        );
    }

    /**
     * Build an authenticated HTTP client.
     */
    private function buildHttpClient(): PendingRequest
    {
        $client = Http::timeout($this->config['http']['timeout'])
            ->connectTimeout($this->config['http']['connect_timeout'])
            ->acceptJson()
            ->withOptions(['verify' => $this->config['http']['verify_ssl']]);

        if ($this->authMethod === 'token') {
            // API Key + Secret token auth
            $apiKey = $this->config['api_key'];
            $apiSecret = $this->config['api_secret'];
            $client = $client->withHeaders([
                'Authorization' => "token {$apiKey}:{$apiSecret}",
            ]);
        } else {
            // Session-based auth (cookie)
            $cookie = $this->getSessionCookie();
            if ($cookie) {
                $client = $client->withCookies(['sid' => $cookie], parse_url($this->baseUrl, PHP_URL_HOST));
            }
        }

        return $client;
    }

    /**
     * Get or create a session cookie for session-based auth.
     */
    private function getSessionCookie(): ?string
    {
        if ($this->sessionCookie) {
            return $this->sessionCookie;
        }

        // Try cache first
        $cached = Cache::get('erpnext_session_cookie');
        if ($cached) {
            $this->sessionCookie = $cached;
            return $cached;
        }

        // Login to get session
        try {
            $response = Http::timeout($this->config['http']['timeout'])
                ->acceptJson()
                ->withOptions(['verify' => $this->config['http']['verify_ssl']])
                ->post("{$this->baseUrl}/api/method/login", [
                    'usr' => $this->config['username'],
                    'pwd' => $this->config['password'],
                ]);

            if ($response->successful()) {
                $cookies = $response->cookies();
                $sid = $cookies->getCookieByName('sid')?->getValue();

                if ($sid && $sid !== 'Guest') {
                    $this->sessionCookie = $sid;
                    Cache::put('erpnext_session_cookie', $sid, now()->addHours(6));
                    return $sid;
                }
            }

            Log::channel('erpnext')->error('ERPNext session login failed', [
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

        } catch (Exception $e) {
            Log::channel('erpnext')->error('ERPNext session login exception', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    // ──────────────────────────────────────────────
    // Circuit Breaker
    // ──────────────────────────────────────────────

    private function checkCircuitBreaker(): void
    {
        $cb = $this->config['sync']['circuit_breaker'];
        $failureCount = Cache::get('erpnext_cb_failures', 0);
        $circuitOpen = Cache::get('erpnext_cb_open', false);

        if ($circuitOpen) {
            throw new ErpNextCircuitOpenException(
                "ERPNext circuit breaker is OPEN. "
                . "Too many failures ({$failureCount}) in the last {$cb['window_seconds']}s. "
                . "Waiting {$cb['recovery_seconds']}s before retrying."
            );
        }
    }

    private function recordSuccess(): void
    {
        Cache::forget('erpnext_cb_failures');
        Cache::forget('erpnext_cb_open');
    }

    private function recordFailure(): void
    {
        $cb = $this->config['sync']['circuit_breaker'];
        $failures = Cache::increment('erpnext_cb_failures');

        // Set TTL on first failure
        if ($failures === 1) {
            Cache::put('erpnext_cb_failures', 1, now()->addSeconds($cb['window_seconds']));
        }

        if ($failures >= $cb['failure_threshold']) {
            Cache::put('erpnext_cb_open', true, now()->addSeconds($cb['recovery_seconds']));
            Cache::forget('erpnext_cb_failures');

            Log::channel('erpnext')->critical("ERPNext circuit breaker OPENED", [
                'failures' => $failures,
                'recovery_seconds' => $cb['recovery_seconds'],
            ]);
        }
    }
}

// ──────────────────────────────────────────────
// Custom Exception Classes
// ──────────────────────────────────────────────

class ErpNextConnectionException extends Exception {}
class ErpNextAuthException extends Exception {}
class ErpNextValidationException extends Exception {}
class ErpNextDuplicateException extends Exception {}
class ErpNextServerException extends Exception {}
class ErpNextCircuitOpenException extends Exception {}
