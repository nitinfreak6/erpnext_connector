<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Facades\Log;

class ShopifyService
{
    private Client $client;
    private string $baseUri;
    private const BUCKET_MAX     = 40;
    private const BUCKET_SAFE    = 35;

	public function __construct()
	{
		$settings    = app(\App\Services\SettingsService::class);
		$shop        = $settings->shopifyShop() ?: config('shopify.shop');
		$apiVersion  = config('shopify.api_version');
		$accessToken = $settings->shopifyAccessToken() ?: config('shopify.access_token');

        $this->baseUri = "https://{$shop}.myshopify.com/admin/api/{$apiVersion}/";

        $this->client = new Client([
            'base_uri' => $this->baseUri,
            'headers'  => [
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type'           => 'application/json',
                'Accept'                 => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    public function get(string $endpoint, array $params = []): array
    {
        return $this->request('GET', $endpoint, ['query' => $params]);
    }

    public function post(string $endpoint, array $body): array
    {
        return $this->request('POST', $endpoint, ['json' => $body]);
    }

    public function put(string $endpoint, array $body): array
    {
        return $this->request('PUT', $endpoint, ['json' => $body]);
    }

    public function delete(string $endpoint): array
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Paginate through all results using Link header cursor.
     */
    public function paginate(string $endpoint, array $params = [], int $limit = 250): \Generator
    {
        $params['limit'] = $limit;
        $nextUrl = $endpoint;
        $isFirst = true;

        while ($nextUrl) {
            $options = $isFirst ? ['query' => $params] : [];
            $fullUrl = $isFirst ? $nextUrl : null;

            ['body' => $body, 'headers' => $headers] = $this->requestWithHeaders(
                'GET',
                $isFirst ? $endpoint : $nextUrl,
                $options,
                !$isFirst
            );

            yield $body;

            $isFirst = false;
            $nextUrl = $this->extractNextLink($headers);
        }
    }

    private function request(string $method, string $endpoint, array $options = []): array
    {
        ['body' => $body] = $this->requestWithHeaders($method, $endpoint, $options);

        return $body;
    }

    private function requestWithHeaders(string $method, string $endpoint, array $options = [], bool $absoluteUrl = false): array
    {
        try {
            $uri      = $absoluteUrl ? $endpoint : $endpoint;
            $response = $this->client->request($method, $uri, $options);

            $this->handleRateLimit($response->getHeader('X-Shopify-Shop-Api-Call-Limit'));

            $body    = json_decode((string) $response->getBody(), true) ?? [];
            $headers = $response->getHeaders();

            return ['body' => $body, 'headers' => $headers];
        } catch (ClientException $e) {
            $status   = $e->getResponse()->getStatusCode();
            $respBody = json_decode((string) $e->getResponse()->getBody(), true);

            throw new ShopifyApiException(
                "Shopify {$method} {$endpoint} returned HTTP {$status}: " . json_encode($respBody),
                $status,
                $endpoint,
                $respBody,
                $e
            );
        } catch (ServerException $e) {
            $status = $e->getResponse()->getStatusCode();

            throw new ShopifyApiException(
                "Shopify server error {$status} on {$method} {$endpoint}",
                $status,
                $endpoint,
                null,
                $e
            );
        }
    }

    /**
     * Back-pressure when near the rate limit bucket ceiling.
     */
    private function handleRateLimit(array $header): void
    {
        if (empty($header)) {
            return;
        }

        [$current, $max] = explode('/', $header[0]) + [0, self::BUCKET_MAX];

        if ((int) $current >= self::BUCKET_SAFE) {
            $sleep = (int) ceil(($current - self::BUCKET_SAFE) / 2) * 500_000; // microseconds
            Log::debug('Shopify rate limit back-pressure', ['current' => $current, 'sleep_us' => $sleep]);
            usleep($sleep);
        }
    }

    private function extractNextLink(array $headers): ?string
    {
        if (empty($headers['Link'])) {
            return null;
        }

        $linkHeader = is_array($headers['Link']) ? $headers['Link'][0] : $headers['Link'];

        if (preg_match('/<([^>]+)>;\s*rel="next"/', $linkHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    // ══════════════════════════════════════════════════════════════════════
    // WEBHOOK MANAGEMENT
    // ══════════════════════════════════════════════════════════════════════

    /**
     * List all registered webhooks
     */
    public function listWebhooks(): array
    {
        $response = $this->get('webhooks.json');
        return $response['webhooks'] ?? [];
    }

    /**
     * Create a webhook
     * 
     * @param string $topic E.g., 'orders/create', 'products/update'
     * @param string $address Full URL where webhook should POST
     */
    public function createWebhook(string $topic, string $address): array
    {
        $response = $this->post('webhooks.json', [
            'webhook' => [
                'topic' => $topic,
                'address' => $address,
                'format' => 'json',
            ]
        ]);
        
        return $response['webhook'] ?? [];
    }

    /**
     * Delete a webhook by ID
     */
    public function deleteWebhook(int|string $webhookId): void
    {
        $this->delete("webhooks/{$webhookId}.json");
    }

    /**
     * Verify webhook HMAC signature
     * 
     * @param string $data Raw request body
     * @param string $hmacHeader Value of X-Shopify-Hmac-Sha256 header
     */
    public function verifyWebhook(string $data, string $hmacHeader): bool
    {
        $settings = app(\App\Services\SettingsService::class);
        $secret = $settings->shopifyWebhookSecret() ?: config('shopify.webhook_secret');
        
        if (!$secret) {
            Log::warning('Shopify webhook secret not configured');
            return false;
        }

        $calculated = base64_encode(hash_hmac('sha256', $data, $secret, true));
        
        return hash_equals($calculated, $hmacHeader);
    }
}