<?php

namespace App\Services\Amazon;

use App\Exceptions\AmazonApiException;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AmazonService
{
    private Client $client;
    private Client $lwaClient;
    private string $endpoint;
    private string $marketplaceId;
    private string $sellerId;

    public function __construct()
    {
         $settings = app(\App\Services\SettingsService::class);
		 $this->endpoint = rtrim($settings->get('amazon_sp_endpoint') ?? config('amazon.endpoint'), '/');
		 $this->marketplaceId = $settings->amazonMarketplaceId() ?: config('amazon.marketplace_id');
		 $this->sellerId      = $settings->amazonSellerId() ?: config('amazon.seller_id');

        $this->lwaClient = new Client([
            'base_uri' => config('amazon.lwa_token_url'),
            'timeout'  => 10,
        ]);

        $this->client = new Client([
            'base_uri' => $this->endpoint . '/',
            'timeout'  => config('amazon.timeout', 30),
        ]);
    }

    /**
     * Get a valid LWA access token, refreshing if expired.
     * Cached for 55 minutes (tokens expire in 60).
     */
    public function getAccessToken(): string
    {
        return Cache::remember('amazon_lwa_token', now()->addMinutes(55), function () {
			$settings = app(\App\Services\SettingsService::class);

            $response = $this->lwaClient->post(config('amazon.lwa_token_url'), [
                'form_params' => [
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $settings->amazonRefreshToken() ?: config('amazon.refresh_token'),
					'client_id'     => $settings->amazonClientId() ?: config('amazon.client_id'),
					'client_secret' => $settings->amazonClientSecret() ?: config('amazon.client_secret'),
                ],
            ]);

            $data = json_decode((string) $response->getBody(), true);

            if (empty($data['access_token'])) {
                throw new AmazonApiException('LWA token refresh returned no access_token', 0, 'lwa');
            }

            Log::debug('Amazon LWA token refreshed.');

            return $data['access_token'];
        });
    }

    public function getMarketplaceId(): string
    {
        return $this->marketplaceId;
    }

    public function getSellerId(): string
    {
        return $this->sellerId;
    }

    /**
     * HTTP GET against SP-API.
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /**
     * HTTP POST against SP-API.
     */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, ['json' => $body]);
    }

    /**
     * HTTP PUT against SP-API.
     */
    public function put(string $path, array $body): array
    {
        return $this->request('PUT', $path, ['json' => $body]);
    }

    /**
     * HTTP DELETE against SP-API.
     */
    public function delete(string $path): array
    {
        return $this->request('DELETE', $path);
    }

    /**
     * Upload content to an Amazon document URL (for feed documents).
     */
    public function uploadDocument(string $uploadUrl, string $content, string $contentType = 'application/json'): void
    {
        $uploadClient = new Client(['timeout' => 60]);

        $uploadClient->put($uploadUrl, [
            'headers' => ['Content-Type' => $contentType],
            'body'    => $content,
        ]);
    }

    /**
     * Download a result document from Amazon.
     */
    public function downloadDocument(string $documentUrl): string
    {
        $downloadClient = new Client(['timeout' => 60]);
        $response       = $downloadClient->get($documentUrl);

        return (string) $response->getBody();
    }

   public function getWithToken(string $token, string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query], $token);
    }

    private function request(string $method, string $path, array $options = [], ?string $overrideToken = null): array
    {
        $token = $overrideToken ?? $this->getAccessToken();
        $options['headers'] = array_merge($options['headers'] ?? [], [
            'x-amz-access-token' => $token,
            'x-amz-date'         => gmdate('Ymd\THis\Z'),
            'Content-Type'       => 'application/json',
            'Accept'             => 'application/json',
        ]);

        try {
            $response = $this->client->request($method, ltrim($path, '/'), $options);

            return json_decode((string) $response->getBody(), true) ?? [];
        } catch (ClientException $e) {
            $status   = $e->getResponse()->getStatusCode();
            $respBody = json_decode((string) $e->getResponse()->getBody(), true);
            $errors   = $respBody['errors'] ?? null;

            // Token may have been invalidated — clear cache so next call refreshes
            if ($status === 403) {
                Cache::forget('amazon_lwa_token');
            }

            // Throttle: caller should retry with backoff
            if ($status === 429) {
                Log::warning("Amazon SP-API throttled on {$method} {$path}");
            }

            throw new AmazonApiException(
                "Amazon SP-API {$method} {$path} returned HTTP {$status}: " . json_encode($errors),
                $status,
                $path,
                $errors,
                $e
            );
        } catch (ServerException $e) {
            $status = $e->getResponse()->getStatusCode();

            throw new AmazonApiException(
                "Amazon SP-API server error {$status} on {$method} {$path}",
                $status,
                $path,
                null,
                $e
            );
        }
    }
}
