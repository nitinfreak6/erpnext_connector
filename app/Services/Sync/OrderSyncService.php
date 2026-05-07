<?php

namespace App\Services\Sync;

use App\Models\ChannelMapping;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\ChannelMappingService;
use App\Services\MappingService;
use App\Services\Odoo\OdooCustomerService;
use App\Services\Odoo\OdooOrderService;
use Illuminate\Support\Facades\Log;

class OrderSyncService
{
    public function __construct(
        private readonly OdooOrderService      $odooOrders,
        private readonly OdooCustomerService   $odooCustomers,
        private readonly MappingService        $mappings,
        private readonly ChannelMappingService $channelMappings,
    ) {}

    /**
     * Create an Odoo sale.order from a Shopify order payload.
     */
    public function createInOdoo(array $shopifyOrder): int
    {
        $shopifyOrderId = (string) $shopifyOrder['id'];

        if ($this->mappings->findByShopifyId(SyncMapping::TYPE_ORDER, $shopifyOrderId)) {
            Log::info("Shopify order #{$shopifyOrderId} already in Odoo, skipping.");
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

            // Shipping line
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

            // ── Wire: Sales Order Type mapping ───────────────────────────
            $orderTypeId = $this->channelMappings->odooSalesOrderType(ChannelMapping::CHANNEL_SHOPIFY);
            if ($orderTypeId) {
                $orderData['type_id'] = (int) $orderTypeId;
            }

            // ── Wire: Sales Rep mapping ──────────────────────────────────
            $salesRepId = $this->channelMappings->odooSalesRep(ChannelMapping::CHANNEL_SHOPIFY);
            if ($salesRepId) {
                $orderData['user_id'] = (int) $salesRepId;
            }

            // ── Wire: Pricelist mapping (by currency) ────────────────────
            $currency    = $shopifyOrder['currency'] ?? '';
            $pricelistId = $currency
                ? $this->channelMappings->odooPricelist($currency, ChannelMapping::CHANNEL_SHOPIFY)
                : null;
            if ($pricelistId) {
                $orderData['pricelist_id'] = (int) $pricelistId;
            }

            // ── Wire: Channel mapping → Odoo sales team ──────────────────
            $teamId = $this->channelMappings->resolve(
                ChannelMapping::TYPE_CHANNEL,
                ChannelMapping::CHANNEL_SHOPIFY,
                'shopify'
            );
            if ($teamId) {
                $orderData['team_id'] = (int) $teamId;
            }

            $odooOrderId = $this->odooOrders->createFromShopify($orderData);

            // Auto-confirm if paid
            if (in_array($shopifyOrder['financial_status'] ?? '', ['paid', 'partially_paid'])) {
                $this->odooOrders->confirmOrder($odooOrderId);
            }

            $this->mappings->upsert(SyncMapping::TYPE_ORDER, (string) $odooOrderId, $shopifyOrderId, [
                'shopify_handle' => $shopifyOrder['name'],
                'last_synced_at' => now(),
            ]);

            $log->markSuccess(json_encode(['odoo_order_id' => $odooOrderId]));
            Log::info("Shopify order #{$shopifyOrderId} → Odoo #{$odooOrderId}");

            return $odooOrderId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
            throw $e;
        }
    }

    // ────────────────────────────────────────────────────────────────────
    // Private helpers
    // ────────────────────────────────────────────────────────────────────

    private function resolveOrCreatePartner(array $shopifyOrder): int
    {
        $email = $shopifyOrder['email'] ?? '';

        if ($email) {
            $existing = $this->odooCustomers->findByEmail($email);
            if ($existing) return $existing['id'];
        }

        $billing   = $shopifyOrder['billing_address'] ?? $shopifyOrder['shipping_address'] ?? [];
        $countryId = null;
        $stateId   = null;

        if (!empty($billing['country_code'])) {
            $countryId = $this->odooCustomers->resolveCountry($billing['country_code']);
            if ($countryId && !empty($billing['province_code'])) {
                $stateId = $this->odooCustomers->resolveState($countryId, $billing['province_code']);
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

        return $this->odooCustomers->create($partnerData);
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

            // ── Wire: Tax mapping ────────────────────────────────────────
            $taxIds = $this->resolveTaxIds($item['tax_lines'] ?? [], ChannelMapping::CHANNEL_SHOPIFY);
            if ($taxIds) {
                $line[2]['tax_id'] = [[6, 0, $taxIds]]; // Odoo many2many replace command
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

        // ── Wire: Shipping mapping → Odoo delivery product ──────────────
        $shippingTitle  = $shippingLine['title'] ?? '';
        $shippingProdId = $this->channelMappings->odooShippingProduct($shippingTitle);
        if ($shippingProdId) {
            $line[2]['product_id'] = (int) $shippingProdId;
        }

        return $line;
    }

    /**
     * Resolve Shopify tax lines → Odoo tax IDs via Tax mapping.
     */
    private function resolveTaxIds(array $taxLines, string $channel): array
    {
        $taxIds = [];
        foreach ($taxLines as $taxLine) {
            $title = $taxLine['title'] ?? '';
            $taxId = $this->channelMappings->odooTax($title, $channel);
            if ($taxId) {
                $taxIds[] = (int) $taxId;
            }
        }
        return array_unique($taxIds);
    }
}