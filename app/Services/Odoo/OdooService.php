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

    /** @var array<int, array<string, mixed>> Captured RPC calls for sync detail pages */
    private array $wireLog = [];

    public function __construct()
    {
        $settings       = app(\App\Services\SettingsService::class);
        $this->url      = $this->normalizeOdooBaseUrl($settings->odooUrl() ?: config('odoo.url', ''));
        $this->db       = $settings->odooDb() ?: config('odoo.db');
        $this->username = $settings->odooUsername() ?: config('odoo.username');
        $this->apiKey   = $settings->odooApiKey() ?: config('odoo.api_key');
        $this->timeout  = config('odoo.timeout', 30);
        $this->encoder  = new Encoder();

        // Parse retry delays from config (overridable via env)
        $raw = config('odoo.retry_delays', '10,30,90');
        $this->retryDelays = array_map('intval', explode(',', $raw));
    }

    /**
     * Normalize the configured Odoo base URL.
     * Odoo SaaS / Odoo.sh reject plain HTTP with a 303 redirect to HTTPS, which
     * breaks XML-RPC clients that expect a 200 response body.
     */
    private function normalizeOdooBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        // Strip browser UI paths — not the Odoo install root (e.g. keep https://host/odoo).
        $url = preg_replace('#/web(/.*)?$#', '', rtrim($url, '/')) ?? rtrim($url, '/');
        // Common mistake: pasting the Laravel connector URL (.../public).
        $url = preg_replace('#/public/?$#', '', $url) ?? $url;

        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['host'])) {
            return rtrim($url, '/');
        }

        $host   = strtolower($parsed['host']);
        $scheme = strtolower($parsed['scheme'] ?? 'https');
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';
        $path   = isset($parsed['path']) ? rtrim($parsed['path'], '/') : '';

        if ($scheme === 'http' && $this->hostRequiresHttps($host)) {
            $scheme = 'https';
            Log::info('OdooService: upgraded HTTP to HTTPS for Odoo cloud host', ['host' => $host]);
        }

        $normalized = "{$scheme}://{$host}{$port}{$path}";

        if (str_contains($path, 'public') || str_contains($path, 'dashboard')) {
            Log::warning('OdooService: ERP URL looks like the connector app, not Odoo', [
                'url' => $normalized,
                'hint' => 'Settings → Odoo URL must be your Odoo server (e.g. https://mycompany.odoo.com), not this Laravel app URL.',
            ]);
        }

        return $normalized;
    }

    private function hostRequiresHttps(string $host): bool
    {
        return str_ends_with($host, '.odoo.com')
            || str_ends_with($host, '.odoo.sh')
            || $host === 'odoo.com';
    }

    private function configureClient(Client $client): void
    {
        $client->setSSLVerifyPeer(true);
        $client->setCurlOptions([
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
        ]);
    }

    // ── Authentication ────────────────────────────────────────────────────

    /**
     * Authenticate and return the Odoo UID.
     * Cached for 8 hours. On 429 the cache avoids unnecessary re-auth.
     */
    public function authenticate(): int
    {
        $response = $this->sendAuthenticateRequest();

        if ($response->faultCode()) {
            $faultString = $response->faultString();

            if ($this->faultStringIsRedirect($faultString) && str_starts_with($this->url, 'http://')) {
                $this->url = 'https://' . substr($this->url, 7);
                Cache::forget('odoo_uid');
                Log::info('OdooService: retrying authentication over HTTPS after redirect', ['url' => $this->url]);
                $response = $this->sendAuthenticateRequest();
                $faultString = $response->faultString();
            }

            if ($response->faultCode()) {
                throw new OdooApiException(
                    $this->formatAuthFaultMessage($faultString),
                    $response->faultCode(),
                    '/xmlrpc/2/common'
                );
            }
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

        Log::info('Odoo authenticated', ['uid' => $uid, 'url' => $this->url]);

        return $uid;
    }

    private function sendAuthenticateRequest(): \PhpXmlRpc\Response
    {
        $client = new Client($this->url . '/xmlrpc/2/common');
        $this->configureClient($client);

        $request = new Request('authenticate', [
            new Value($this->db,       'string'),
            new Value($this->username, 'string'),
            new Value($this->apiKey,   'string'),
            new Value([],              'struct'),
        ]);

        return $this->sendWithRetry($client, $request, '/xmlrpc/2/common');
    }

    private function formatAuthFaultMessage(string $faultString): string
    {
        if ($this->faultStringIsRedirect($faultString)) {
            return 'Odoo authentication fault: the ERP URL redirected instead of responding to XML-RPC (HTTP 303). '
                . 'In Settings → Odoo, use your Odoo server base URL only (e.g. https://yourcompany.odoo.com) — '
                . 'no /web, no /public, and not http://. '
                . 'Do not use this connector app URL. Attempted: ' . $this->url;
        }

        return 'Odoo authentication fault: ' . $faultString;
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

                // Non-retryable: XML-RPC marshal errors (e.g. action_apply_inventory
                // returning None on Odoo SaaS 17/18). Retrying will never fix these.
                if ($this->isNonRetryable($e)) {
                    throw $e;
                }

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
        $this->configureClient($client);

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
            $faultString = $response->faultString();
            $fullMessage = "Odoo {$model}.{$method} failed: " . $faultString;

            Log::warning('OdooService RPC fault', [
                'model'  => $model,
                'method' => $method,
                'fault'  => strlen($faultString) > 2000 ? substr($faultString, 0, 2000) . '…' : $faultString,
            ]);

            throw new OdooApiException(
                $fullMessage,
                $response->faultCode(),
                '/xmlrpc/2/object'
            );
        }

        $result = $this->encoder->decode($response->value());
        $this->recordWireCall($model, $method, $args, $kwargs, $result);

        return $result;
    }

    /**
     * Return captured Odoo XML-RPC calls since the last takeWireLog() and clear the buffer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function takeWireLog(): array
    {
        $log           = $this->wireLog;
        $this->wireLog = [];

        return $log;
    }

    private function recordWireCall(string $model, string $method, array $args, array $kwargs, mixed $result): void
    {
        $this->wireLog[] = [
            'endpoint' => $this->url . '/xmlrpc/2/object',
            'model'    => $model,
            'method'   => $method,
            'args'     => $args,
            'kwargs'   => $kwargs,
            'result'   => $result,
        ];
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

    /**
     * Returns true for errors that are deterministic and will never succeed on retry.
     * Retrying these wastes time and fills logs with noise.
     *
     * Known cases:
     *   - "cannot marshal None" — Odoo SaaS 17/18 action_apply_inventory returns a
     *     window action dict / None which XML-RPC cannot serialize. This is a server-side
     *     response serialization issue, not a transient failure.
     */
    private function isNonRetryable(\Throwable $e): bool
    {
        return $this->isNonRetryableMessage($e->getMessage());
    }

    private function isNonRetryableMessage(string $msg): bool
    {
        $lower = strtolower($msg);

        return str_contains($msg, 'cannot marshal None')
            || str_contains($msg, 'allow_none is enabled')
            || str_contains($msg, 'Quants cannot be created for consumables or services')
            || str_contains($msg, 'Record does not exist or has been deleted')
            || str_contains($msg, 'Invalid field')
            || str_contains($msg, 'Invalid ERP field')
            || str_contains($msg, 'ValueError:')
            || str_contains($msg, 'UserError:')
            || str_contains($msg, 'ValidationError')
            || str_contains($msg, 'AccessError:')
            || str_contains($msg, 'KeyError:')
            || str_contains($msg, 'Traceback')
            || str_contains($msg, 'Wrong value for')
            || str_contains($msg, 'invalid input syntax for type integer')
            || str_contains($msg, 'InvalidTextRepresentation')
            || str_contains($msg, '.write failed:')
            || str_contains($msg, '.create failed:')
            || str_contains($msg, '.unlink failed:')
            || str_contains($msg, 'The operation cannot be completed')
            || str_contains($msg, 'foreign key constraint')
            || str_contains($msg, 'violates RESTRICT')
            || str_contains($msg, 'MissingError')
            || str_contains($lower, 'integrityerror')
            || str_contains($lower, 'still linked')
            || str_contains($lower, 'cannot be deleted')
            || str_contains($lower, 'linked to');
    }

    private function faultStringIs429(string $text): bool
    {
        return str_contains($text, '429')
            || str_contains(strtolower($text), 'too many requests')
            || str_contains(strtolower($text), 'rate limit');
    }

    private function faultStringIsRedirect(string $text): bool
    {
        return str_contains($text, '303')
            || str_contains($text, '302')
            || str_contains($text, '301')
            || str_contains(strtolower($text), 'redirect');
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

    public function getBaseUrl(): string
    {
        return $this->url;
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