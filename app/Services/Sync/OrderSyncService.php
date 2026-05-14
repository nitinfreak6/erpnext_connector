<?php

namespace App\Services\Sync;

use App\Models\ChannelMapping;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\ChannelMappingService;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use Illuminate\Support\Facades\Log;

class OrderSyncService
{
    public function __construct(
        private readonly ErpInterface          $erp,             // ← was OdooOrderService + OdooCustomerService
        private readonly MappingService        $mappings,
        private readonly ChannelMappingService $channelMappings,
    ) {}

    /**
     * Create an ERP order from a Shopify order payload.
     */
    public function createInErp(array $shopifyOrder): int
    {
        $shopifyOrderId = (string) $shopifyOrder['id'];

        if ($this->mappings->findByShopifyId(SyncMapping::TYPE_ORDER, $shopifyOrderId)) {
            Log::info("Shopify order #{$shopifyOrderId} already in ERP, skipping.");
            return 0;
        }

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_SHOPIFY_TO_ODOO,
            'entity_type'     => SyncMapping::TYPE_ORDER,
            'entity_id'       => $shopifyOrderId,
            'action'          => 'create',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($shopifyOrder),
        ]);

        try {
            $partnerId  = $this->resolveOrCreatePartner($shopifyOrder);
            $orderLines = $this->buildOrderLines($shopifyOrder['line_items'] ?? [], $shopifyOrder);

            if (!empty($shopifyOrder['shipping_lines'][0])) {
                $orderLines[] = $this->buildShippingLine($shopifyOrder['shipping_lines'][0]);
            }

            $orderData = [
                'client_order_ref'    => $shopifyOrder['name'],
                'origin'              => 'Shopify #' . $shopifyOrder['name'],
                'partner_id'          => $partnerId,
                'partner_invoice_id'  => $partnerId,
                'partner_shipping_id' => $partnerId,
                'order_line'          => $orderLines,
                'note'                => $shopifyOrder['note'] ?? '',
                'date_order'          => date('Y-m-d H:i:s', strtotime($shopifyOrder['created_at'])),
            ];

            // ── Channel mappings (unchanged) ────────────────────────────
            $orderTypeId = $this->channelMappings->odooSalesOrderType(ChannelMapping::CHANNEL_SHOPIFY);
            if ($orderTypeId) {
                $orderData['type_id'] = (int) $orderTypeId;
            }

            $salesRepId = $this->channelMappings->odooSalesRep(ChannelMapping::CHANNEL_SHOPIFY);
            if ($salesRepId) {
                $orderData['user_id'] = (int) $salesRepId;
            }

            $currency    = $shopifyOrder['currency'] ?? '';
            $pricelistId = $currency
                ? $this->channelMappings->odooPricelist($currency, ChannelMapping::CHANNEL_SHOPIFY)
                : null;
            if ($pricelistId) {
                $orderData['pricelist_id'] = (int) $pricelistId;
            }

            $teamId = $this->channelMappings->resolve(
                ChannelMapping::TYPE_CHANNEL,
                ChannelMapping::CHANNEL_SHOPIFY,
                'shopify'
            );
            if ($teamId) {
                $orderData['team_id'] = (int) $teamId;
            }

            $erpOrderId = $this->erp->createOrder($orderData);

            if (in_array($shopifyOrder['financial_status'] ?? '', ['paid', 'partially_paid'])) {
                $this->erp->confirmOrder($erpOrderId);
            }

            $this->mappings->upsert(SyncMapping::TYPE_ORDER, (string) $erpOrderId, $shopifyOrderId, [
                'shopify_handle' => $shopifyOrder['name'],
                'last_synced_at' => now(),
            ]);

            $log->markSuccess(json_encode(['erp_order_id' => $erpOrderId]));
            Log::info("Shopify order #{$shopifyOrderId} → ERP #{$erpOrderId} [{$this->erp->driverName()}]");

            return $erpOrderId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
            throw $e;
        }
    }

    /**
     * Kept for backwards compatibility with existing job calls.
     * @deprecated Use createInErp() for new code.
     */
    public function createInOdoo(array $shopifyOrder): int
    {
        return $this->createInErp($shopifyOrder);
    }

    // ── Private helpers ──────────────────────────────────────────────────

    private function resolveOrCreatePartner(array $shopifyOrder): int
    {
        $email = $shopifyOrder['email'] ?? '';

        if ($email) {
            $existing = $this->erp->findCustomerByEmail($email);
            if ($existing) return $existing['id'];
        }

        $billing   = $shopifyOrder['billing_address'] ?? $shopifyOrder['shipping_address'] ?? [];
        $countryId = null;
        $stateId   = null;

        if (!empty($billing['country_code'])) {
            $countryId = $this->erp->resolveCountry($billing['country_code']);
            if ($countryId && !empty($billing['province_code'])) {
                $stateId = $this->erp->resolveState($countryId, $billing['province_code']);
            }
        }

        $name = trim(($billing['first_name'] ?? '') . ' ' . ($billing['last_name'] ?? ''));

        $partnerData = [
            'name'          => $name ?: ($email ?: 'Shopify Customer'),
            'email'         => $email,
            'phone'         => $billing['phone'] ?? '',
            'street'        => $billing['address1'] ?? '',
            'street2'       => $billing['address2'] ?? '',
            'city'          => $billing['city'] ?? '',
            'zip'           => $billing['zip'] ?? '',
            'customer_rank' => 1,
        ];

        if ($countryId) $partnerData['country_id'] = $countryId;
        if ($stateId)   $partnerData['state_id']   = $stateId;

        return $this->erp->createCustomer($partnerData);
    }

    private function buildOrderLines(array $lineItems, array $shopifyOrder): array
    {
        $lines = [];

        foreach ($lineItems as $item) {
            $variantId      = (string) ($item['variant_id'] ?? '');
            $variantMapping = $variantId
                ? $this->mappings->findByShopifyId(SyncMapping::TYPE_PRODUCT_VARIANT, $variantId)
                : null;

            $line = [0, 0, [
                'name'            => $item['title'] . (!empty($item['variant_title']) ? ' - ' . $item['variant_title'] : ''),
                'product_uom_qty' => (float) $item['quantity'],
                'price_unit'      => (float) $item['price'],
            ]];

            if ($variantMapping) {
                $line[2]['product_id'] = (int) $variantMapping->odoo_id;
            } else {
                $line[2]['name'] .= ' [MISSING PRODUCT]';
            }

            $taxIds = $this->resolveTaxIds($item['tax_lines'] ?? [], ChannelMapping::CHANNEL_SHOPIFY);
            if ($taxIds) {
                $line[2]['tax_id'] = [[6, 0, $taxIds]];
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function buildShippingLine(array $shippingLine): array
    {
        $line = [0, 0, [
            'name'            => 'Shipping: ' . ($shippingLine['title'] ?? 'Standard'),
            'product_uom_qty' => 1,
            'price_unit'      => (float) $shippingLine['price'],
        ]];

        $shippingProdId = $this->channelMappings->odooShippingProduct($shippingLine['title'] ?? '');
        if ($shippingProdId) {
            $line[2]['product_id'] = (int) $shippingProdId;
        }

        return $line;
    }

    private function resolveTaxIds(array $taxLines, string $channel): array
    {
        $taxIds = [];
        foreach ($taxLines as $taxLine) {
            $taxId = $this->channelMappings->odooTax($taxLine['title'] ?? '', $channel);
            if ($taxId) {
                $taxIds[] = (int) $taxId;
            }
        }
        return array_unique($taxIds);
    }
}
