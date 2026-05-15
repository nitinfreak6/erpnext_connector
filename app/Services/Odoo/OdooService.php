<?php

namespace App\Services\Odoo;

use App\Exceptions\OdooApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PhpXmlRpc\Client;
use PhpXmlRpc\Encoder;
use PhpXmlRpc\Request;
use PhpXmlRpc\Value;

class OdooService
{
    private Encoder $encoder;
    private string  $url;
    private string  $db;
    private string  $username;
    private string  $apiKey;
    private int     $timeout;

    /**
     * Retry config for 429 / transient errors.
     *
     * Delays (seconds) between attempts — exponential with jitter:
     *   attempt 1 fails → wait RETRY_DELAYS[0]  + jitter
     *   attempt 2 fails → wait RETRY_DELAYS[1]  + jitter
     *   attempt 3 fails → give up and throw
     *
     * Set ODOO_RETRY_DELAYS in .env as a comma-separated list to override,
     * e.g.  ODOO_RETRY_DELAYS=5,30,120
     */
    private array $retryDelays;

    public function __construct()
    {
        $settings       = app(\App\Services\SettingsService::class);
        $this->url      = rtrim($settings->odooUrl() ?: config('odoo.url'), '/');
        $this->db       = $settings->odooDb() ?: config('odoo.db');
        $this->username = $settings->odooUsername() ?: config('odoo.username');
        $this->apiKey   = $settings->odooApiKey() ?: config('odoo.api_key');
        $this->timeout  = config('odoo.timeout', 30);
        $this->encoder  = new Encoder();

        // Parse retry delays from config (overridable via env)
        $raw = config('odoo.retry_delays', '10,30,90');
        $this->retryDelays = array_map('intval', explode(',', $raw));
    }

    // ── Authentication ────────────────────────────────────────────────────

    /**
     * Authenticate and return the Odoo UID.
     * Cached for 8 hours. On 429 the cache avoids unnecessary re-auth.
     */
    public function authenticate(): int
    {
        $client = new Client($this->url . '/xmlrpc/2/common');

        $request = new Request('authenticate', [
            new Value($this->db,       'string'),
            new Value($this->username, 'string'),
            new Value($this->apiKey,   'string'),
            new Value([],              'struct'),
        ]);

        $response = $this->sendWithRetry($client, $request, '/xmlrpc/2/common');

        if ($response->faultCode()) {
            throw new OdooApiException(
                'Odoo authentication fault: ' . $response->faultString(),
                $response->faultCode(),
                '/xmlrpc/2/common'
            );
        }

        $uid = $response->value()->scalarval();

        if (!$uid || !is_numeric($uid)) {
            throw new OdooApiException(
                'Odoo authentication returned invalid UID: ' . var_export($uid, true),
                0,
                '/xmlrpc/2/common'
            );
        }

        $uid = (int) $uid;

        Log::info('Odoo authenticated', ['uid' => $uid]);

        return $uid;
    }

    private function getUid(): int
    {
        return Cache::remember('odoo_uid', now()->addHours(8), function () {
            return $this->authenticate();
        });
    }

    // ── Execute ───────────────────────────────────────────────────────────

    /**
     * Execute a method on an Odoo model.
     *
     * On failure (including 429): waits with exponential back-off then
     * retries with fresh auth. If all retries are exhausted, throws.
     */
    public function executeKw(string $model, string $method, array $args = [], array $kwargs = []): mixed
    {
        $uid       = $this->getUid();
        $lastError = null;

        // +1 for the first attempt
        $attempts = count($this->retryDelays) + 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->doExecute($model, $method, $args, $kwargs, $uid);
            } catch (\Throwable $e) {
                $lastError = $e;
                $is429     = $this->is429($e);

                Log::warning("OdooService: attempt {$attempt}/{$attempts} failed", [
                    'model'  => $model,
                    'method' => $method,
                    'error'  => $e->getMessage(),
                    'is_429' => $is429,
                ]);

                // No more retries left
                if ($attempt === $attempts) {
                    break;
                }

                // Wait before next attempt
                $delay  = $this->retryDelays[$attempt - 1] ?? end($this->retryDelays);
                $jitter = random_int(0, (int) round($delay * 0.2)); // ±20% jitter
                $sleep  = $delay + $jitter;

                Log::info("OdooService: waiting {$sleep}s before retry (attempt {$attempt})...");
                sleep($sleep);

                // Refresh auth token before retry (stale token can also cause failures)
                Cache::forget('odoo_uid');
                try {
                    $uid = $this->authenticate();
                } catch (\Throwable $authEx) {
                    Log::warning('OdooService: re-auth also failed: ' . $authEx->getMessage());
                    // Keep existing uid; doExecute will fail again and we'll retry
                }
            }
        }

        throw $lastError;
    }

    private function doExecute(string $model, string $method, array $args, array $kwargs, int $uid): mixed
    {
        if ($uid <= 0) {
            throw new OdooApiException('Invalid UID supplied to Odoo: ' . $uid);
        }

        $client = new Client($this->url . '/xmlrpc/2/object');
        $client->setSSLVerifyPeer(true);
        $client->setCurlOptions([CURLOPT_TIMEOUT => $this->timeout]);

        $request = new Request('execute_kw', [
            new Value($this->db,     'string'),
            new Value($uid,          'int'),
            new Value($this->apiKey, 'string'),
            new Value($model,        'string'),
            new Value($method,       'string'),
            $this->encoder->encode($args),
            $this->encoder->encode($kwargs),
        ]);

        $response = $this->sendWithRetry($client, $request, '/xmlrpc/2/object');

        if ($response->faultCode()) {
            throw new OdooApiException(
                "Odoo {$model}.{$method} failed: " . $response->faultString(),
                $response->faultCode(),
                '/xmlrpc/2/object'
            );
        }

        return $this->encoder->decode($response->value());
    }

    // ── Low-level HTTP send with 429-aware retry ──────────────────────────

    /**
     * Send a single XML-RPC request, retrying on 429 with back-off.
     * This handles the case where the HTTP layer itself returns 429
     * before the XML-RPC response is even parsed.
     */
    private function sendWithRetry(Client $client, Request $request, string $endpoint): \PhpXmlRpc\Response
    {
        $attempts = count($this->retryDelays) + 1;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $client->send($request);

            // PhpXmlRpc surfaces HTTP-level errors as fault code -32300
            // and puts the HTTP status in the fault string
            if (!$response->faultCode()) {
                return $response; // success
            }

            $faultString = $response->faultString();

            if (!$this->faultStringIs429($faultString)) {
                return $response; // real fault, not a rate limit — let caller handle it
            }

            if ($attempt === $attempts) {
                Log::error("OdooService: 429 on {$endpoint} after {$attempts} attempts, giving up.");
                return $response;
            }

            $delay  = $this->retryDelays[$attempt - 1] ?? end($this->retryDelays);
            $jitter = random_int(0, (int) round($delay * 0.2));
            $sleep  = $delay + $jitter;

            Log::warning("OdooService: 429 on {$endpoint} (attempt {$attempt}/{$attempts}), sleeping {$sleep}s...");
            sleep($sleep);
        }

        // Unreachable but satisfies static analysis
        return $client->send($request);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Check whether an exception message indicates a 429 rate limit.
     */
    private function is429(\Throwable $e): bool
    {
        return $this->faultStringIs429($e->getMessage());
    }

    private function faultStringIs429(string $text): bool
    {
        return str_contains($text, '429')
            || str_contains(strtolower($text), 'too many requests')
            || str_contains(strtolower($text), 'rate limit');
    }

    // ── Convenience wrappers (unchanged public API) ───────────────────────

    public function search(string $model, array $domain = [], array $options = []): array
    {
        return $this->executeKw($model, 'search', [$domain], $options);
    }

    public function searchRead(string $model, array $domain = [], array $fields = [], array $options = []): array
    {
        $kwargs = array_merge(['fields' => $fields], $options);
        return $this->executeKw($model, 'search_read', [$domain], $kwargs);
    }

    public function read(string $model, array $ids, array $fields = []): array
    {
        return $this->executeKw($model, 'read', [$ids], ['fields' => $fields]);
    }

    public function create(string $model, array $values): int
    {
        return (int) $this->executeKw($model, 'create', [$values]);
    }

    public function write(string $model, array $ids, array $values): bool
    {
        return (bool) $this->executeKw($model, 'write', [$ids, $values]);
    }

    public function unlink(string $model, array $ids): bool
    {
        return (bool) $this->executeKw($model, 'unlink', [$ids]);
    }

    public function getModifiedSince(string $model, string $writeDate, array $fields = [], array $extraDomain = []): array
    {
        $domain = array_merge(
            [['date_order', '>', $writeDate]],
            $extraDomain
        );

        return $this->searchRead($model, $domain, $fields, [
            'order' => 'date_order asc',
        ]);
    }

    public function clearSession(): void
    {
        Cache::forget('odoo_uid');
    }

    // ── Private (unchanged) ───────────────────────────────────────────────

    private function phpToValue($data, bool $isStruct = false): Value
    {
        if (is_array($data)) {
            if ($isStruct) {
                $struct = [];
                foreach ($data as $key => $value) {
                    $struct[$key] = $this->phpToValue($value);
                }
                return new Value($struct, 'struct');
            } else {
                $arr = [];
                foreach ($data as $value) {
                    $arr[] = $this->phpToValue($value);
                }
                return new Value($arr, 'array');
            }
        }

        if (is_int($data))  return new Value($data, 'int');
        if (is_bool($data)) return new Value($data, 'boolean');

        return new Value((string) $data, 'string');
    }
}
