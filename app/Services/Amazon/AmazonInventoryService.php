<?php

namespace App\Services\Amazon;

use App\Exceptions\AmazonApiException;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

class AmazonInventoryService
{
    private const LISTINGS_VERSION = '2021-08-01';

    public function __construct(
        private readonly AmazonService        $amazon,
        private readonly AmazonListingService $listings,
        private readonly SettingsService      $settings,
    ) {}

    /**
     * Update quantity for a FBM listing via Listings Items PATCH.
     * For FBA, Amazon manages inventory — do not call this.
     */
    public function updateQuantity(string $sku, int $quantity): array
    {
        // ✅ Read from DB via SettingsService, not config()
        $fulfillmentChannel = $this->settings->get('amazon_fulfillment_channel')
            ?? config('amazon.fulfillment_channel', 'FBM');

        if (strtoupper($fulfillmentChannel) === 'FBA') {
            Log::debug("Amazon FBA mode — skipping inventory push for SKU {$sku}");
            return [];
        }

        $sellerId      = $this->amazon->getSellerId();
        $marketplaceId = $this->amazon->getMarketplaceId();

        $path = "/listings/" . self::LISTINGS_VERSION
              . "/items/{$sellerId}/" . rawurlencode($sku)
              . "?marketplaceIds=" . rawurlencode($marketplaceId);

        $body = [
            'productType' => 'PRODUCT',
            'patches'     => [
                [
                    'op'    => 'replace',
                    'path'  => '/attributes/fulfillment_availability',
                    'value' => [
                        [
                            'fulfillment_channel_code' => 'DEFAULT',
                            'quantity'                 => $quantity,
                            'marketplace_id'           => $marketplaceId,
                        ],
                    ],
                ],
            ],
        ];

        // ✅ Use AmazonService::patch() — it uses the DB-configured endpoint + token
        try {
            $result = $this->amazon->patch($path, $body);

            Log::info("Amazon inventory updated: SKU={$sku} qty={$quantity}");

            return $result;
        } catch (AmazonApiException $e) {
            throw new AmazonApiException(
                "Amazon inventory PATCH failed for SKU {$sku}: HTTP {$e->getHttpStatus()}",
                $e->getHttpStatus(),
                $path,
                $e->getErrors(),
                $e
            );
        }
    }

    /**
     * Get FBA inventory summaries (read-only — for reconciliation).
     */
    public function getFbaInventory(array $skus = []): array
    {
        $params = [
            'granularityType' => 'Marketplace',
            'granularityId'   => $this->amazon->getMarketplaceId(),
            'marketplaceIds'  => [$this->amazon->getMarketplaceId()],
        ];

        if ($skus) {
            $params['sellerSkus'] = implode(',', $skus);
        }

        $response = $this->amazon->get('/fba/inventory/v1/summaries', $params);

        return $response['payload']['inventorySummaries'] ?? [];
    }
}