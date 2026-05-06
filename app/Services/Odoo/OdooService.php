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
    private string $url;
    private string $db;
    private string $username;
    private string $apiKey;
    private int $timeout;
    

		public function __construct()
	{
		$settings       = app(\App\Services\SettingsService::class);
		$this->url      = rtrim($settings->odooUrl() ?: config('odoo.url'), '/');
		$this->db       = $settings->odooDb() ?: config('odoo.db');
		$this->username = $settings->odooUsername() ?: config('odoo.username');
		$this->apiKey   = $settings->odooApiKey() ?: config('odoo.api_key');
        $this->timeout  = config('odoo.timeout', 30);
		$this->encoder = new Encoder();
        
    }

    /**
     * Authenticate and return Odoo user ID (uid).
     * Result is cached for 8 hours.
     */
public function authenticate(): int
{
    $client = new Client($this->url . '/xmlrpc/2/common');

    $request = new Request('authenticate', [
        new Value($this->db, 'string'),
        new Value($this->username, 'string'),
        new Value($this->apiKey, 'string'),
        new Value([], 'struct'),
    ]);

    $response = $client->send($request);

    if ($response->faultCode()) {
        throw new OdooApiException(
            'Odoo authentication fault: ' . $response->faultString(),
            $response->faultCode(),
            '/xmlrpc/2/common'
        );
    }

    $uid = $response->value()->scalarval();

    // 🔴 THIS WAS MISSING
    if (!$uid || !is_numeric($uid)) {
        throw new OdooApiException(
            'Odoo authentication returned invalid UID: ' . var_export($uid, true),
            0,
            '/xmlrpc/2/common'
        );
    }

    $uid = (int) $uid;

    Log::warning('Odoo authenticated', ['uid' => $uid]);

    return $uid;
}

private function getUid(): int
{
    return Cache::remember('odoo_uid', now()->addMinutes(15), function () {
        return $this->authenticate();
    });
}

    /**
     * Execute a method on an Odoo model.
     */
public function executeKw(string $model, string $method, array $args = [], array $kwargs = []): mixed
{
    try {
        return $this->doExecute($model, $method, $args, $kwargs, $this->getUid());
    } catch (\Throwable $e) {

        Log::warning('Odoo call failed. Retrying with fresh UID...', [
            'error' => $e->getMessage(),
            'model' => $model,
            'method' => $method,
        ]);

        Cache::forget('odoo_uid');

        // retry once with fresh auth
        $freshUid = $this->authenticate();

        return $this->doExecute($model, $method, $args, $kwargs, $freshUid);
    }
}

private function doExecute(string $model, string $method, array $args, array $kwargs, int $uid): mixed
{
	
	if ($uid <= 0) {
    throw new OdooApiException('Invalid UID supplied to Odoo: ' . $uid);
}
    $client = new Client($this->url . '/xmlrpc/2/object');
    $client->setSSLVerifyPeer(true);
    $client->setCurlOptions([
        CURLOPT_TIMEOUT => $this->timeout,
    ]);

    $params = [
        new Value($this->db, 'string'),
        new Value($uid, 'int'),
        new Value($this->apiKey, 'string'),
        new Value($model, 'string'),
        new Value($method, 'string'),
        $this->encoder->encode($args),
        $this->encoder->encode($kwargs),
    ];

    $request = new Request('execute_kw', $params);
    $response = $client->send($request);

    if ($response->faultCode()) {
        throw new OdooApiException(
            "Odoo {$model}.{$method} failed: " . $response->faultString(),
            $response->faultCode(),
            '/xmlrpc/2/object'
        );
    }

    return $this->encoder->decode($response->value());
}
	
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

    if (is_int($data)) {
        return new Value($data, 'int');
    }

    if (is_bool($data)) {
        return new Value($data, 'boolean');
    }

    return new Value((string) $data, 'string');
}

    /**
     * Search and return IDs.
     */
    public function search(string $model, array $domain = [], array $options = []): array
    {
        return $this->executeKw($model, 'search', [$domain], $options);
    }

    /**
     * Search and read records in one call.
     */
    public function searchRead(string $model, array $domain = [], array $fields = [], array $options = []): array
    {
        $kwargs = array_merge(['fields' => $fields], $options);

        return $this->executeKw($model, 'search_read', [$domain], $kwargs);
    }

    /**
     * Read specific records by IDs.
     */
    public function read(string $model, array $ids, array $fields = []): array
    {
        return $this->executeKw($model, 'read', [$ids], ['fields' => $fields]);
    }

    /**
     * Create a new record. Returns new record ID.
     */
    public function create(string $model, array $values): int
    {
        return (int) $this->executeKw($model, 'create', [$values]);
    }

    /**
     * Update records. Returns true on success.
     */
    public function write(string $model, array $ids, array $values): bool
    {
        return (bool) $this->executeKw($model, 'write', [$ids, $values]);
    }

    /**
     * Delete records.
     */
    public function unlink(string $model, array $ids): bool
    {
        return (bool) $this->executeKw($model, 'unlink', [$ids]);
    }

    /**
     * Get records modified since a given write_date.
     */
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

    /**
     * Invalidate the cached session.
     */
    public function clearSession(): void
    {
        Cache::forget('odoo_uid');
    }
}
