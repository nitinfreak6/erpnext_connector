<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\Log;

class ShopifyGraphQLService
{
    private Client $client;
    private string $endpoint;

    // GraphQL cost-based rate limiting
    // Shopify gives 1000 points/second restore, max bucket 10000
    private const COST_THROTTLE_THRESHOLD = 500;
    private const COST_RESTORE_PER_SEC    = 1000;

    public function __construct()
    {
        $settings    = app(\App\Services\SettingsService::class);
        $shop        = $settings->shopifyShop() ?: config('shopify.shop');
        $apiVersion  = config('shopify.api_version', '2024-01');
        $accessToken = $settings->shopifyAccessToken() ?: config('shopify.access_token');

        $this->endpoint = "https://{$shop}.myshopify.com/admin/api/{$apiVersion}/graphql.json";

        $this->client = new Client([
            'headers' => [
                'X-Shopify-Access-Token' => $accessToken,
                'Content-Type'           => 'application/json',
                'Accept'                 => 'application/json',
            ],
            'timeout' => 30,
        ]);
    }

    /**
     * Execute a GraphQL query or mutation.
     * Returns the 'data' key from the response.
     *
     * Variables are only included in the request body when provided —
     * Shopify rejects an empty variables array ({}) with an "Invalid variables
     * parameter" error.
     */
    public function query(string $query, array $variables = []): array
    {
        try {
            $body = ['query' => $query];

            // Only add variables when non-empty — Shopify rejects variables: []
            if (!empty($variables)) {
                $body['variables'] = $variables;
            }

            $response = $this->client->post($this->endpoint, ['json' => $body]);

            $responseBody = json_decode((string) $response->getBody(), true);

            // GraphQL errors (HTTP 200 but errors array present)
            if (!empty($responseBody['errors'])) {
                $errorMsg = implode('; ', array_column($responseBody['errors'], 'message'));
                throw new ShopifyApiException(
                    "Shopify GraphQL error: {$errorMsg}",
                    200,
                    'graphql.json',
                    $responseBody['errors']
                );
            }

            // Cost-based throttle back-pressure
            $this->handleThrottle($responseBody['extensions']['cost'] ?? null);

            return $responseBody['data'] ?? [];

        } catch (ClientException $e) {
            $status       = $e->getResponse()->getStatusCode();
            $responseBody = json_decode((string) $e->getResponse()->getBody(), true);

            throw new ShopifyApiException(
                "Shopify GraphQL HTTP {$status}: " . json_encode($responseBody),
                $status,
                'graphql.json',
                $responseBody,
                $e
            );
        }
    }

    /**
     * Extract userErrors from a mutation response.
     * Returns flat array of error strings.
     */
    public function extractUserErrors(array $data, string $mutationKey): array
    {
        $userErrors = $data[$mutationKey]['userErrors'] ?? [];
        return array_map(
            fn($e) => ($e['field'] ? implode('.', (array)$e['field']) . ': ' : '') . $e['message'],
            $userErrors
        );
    }

    private function handleThrottle(?array $cost): void
    {
        if (!$cost) return;

        $available   = $cost['throttleStatus']['currentlyAvailable'] ?? 1000;
        $restoreRate = $cost['throttleStatus']['restoreRate']        ?? self::COST_RESTORE_PER_SEC;

        Log::debug('Shopify GraphQL cost', [
            'actual'    => $cost['actualQueryCost'] ?? 0,
            'available' => $available,
        ]);

        if ($available < self::COST_THROTTLE_THRESHOLD) {
            $needed  = self::COST_THROTTLE_THRESHOLD - $available;
            $sleepMs = (int) ceil(($needed / $restoreRate) * 1000);
            Log::debug("Shopify GraphQL throttle: sleeping {$sleepMs}ms");
            usleep($sleepMs * 1000);
        }
    }
}