<?php

namespace App\Services\Sync;

use App\Models\ChannelMapping;
use App\Models\SyncLog;
use App\Services\Amazon\AmazonOrderService;
use App\Services\ChannelMappingService;
use App\Services\Erp\ErpInterface;
use App\Services\MappingService;
use Illuminate\Support\Facades\Log;

/**
 * FIX #22: ErpInterface replaces OdooOrderService + OdooCustomerService.
 * Works with any ERP driver.
 */
class AmazonOrderSyncService
{
    const ENTITY_ORDER = 'amazon_order';

    public function __construct(
        private readonly AmazonOrderService    $amazonOrders,
        private readonly ErpInterface          $erp,           // FIX: was OdooOrderService + OdooCustomerService
        private readonly MappingService        $mappings,
        private readonly ChannelMappingService $channelMappings,
    ) {}

    // FIX: renamed from createInOdoo() to createInErp()
    public function createInErp(array $amazonOrder, array $orderItems): int
    {
        $amazonOrderId = $amazonOrder['AmazonOrderId'];

        if ($this->mappings->findByShopifyId(self::ENTITY_ORDER, $amazonOrderId)) {
            Log::info("Amazon order {$amazonOrderId} already in {$this->erp->driverName()}, skipping.");
            return 0;
        }

        $log = SyncLog::create([
            'direction'       => 'ecom_to_erp',
            'entity_type'     => self::ENTITY_ORDER,
            'entity_id'       => $amazonOrderId,
            'action'          => 'create',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode(['order' => $amazonOrder, 'items' => $orderItems]),
        ]);

        try {
            $partnerId  = $this->resolveOrCreatePartner($amazonOrder);
            $orderLines = $this->buildOrderLines($orderItems);

            $shippingPrice = (float) ($amazonOrder['OrderTotal']['Amount'] ?? 0)
                - array_sum(array_map(fn($i) => (float) ($i['ItemPrice']['Amount'] ?? 0), $orderItems));

            if ($shippingPrice > 0) {
                $shippingLine = [0, 0, [
                    'name'            => 'Amazon Shipping',
                    'product_uom_qty' => 1,
                    'price_unit'      => round($shippingPrice, 2),
                ]];

                $shippingProdId = $this->channelMappings->odooShippingProduct(
                    $amazonOrder['ShipServiceLevel'] ?? 'Amazon Shipping'
                );
                if ($shippingProdId) {
                    $shippingLine[2]['product_id'] = (int) $shippingProdId;
                }

                $orderLines[] = $shippingLine;
            }

            $orderData = [
                'client_order_ref'    => $amazonOrderId,
                'origin'              => 'Amazon #' . $amazonOrderId,
                'partner_id'          => $partnerId,
                'partner_invoice_id'  => $partnerId,
                'partner_shipping_id' => $partnerId,
                'order_line'          => $orderLines,
                'note'                => 'Amazon channel: ' . ($amazonOrder['SalesChannel'] ?? ''),
                'date_order'          => date('Y-m-d H:i:s', strtotime($amazonOrder['PurchaseDate'])),
            ];

            $orderTypeId = $this->channelMappings->odooAmazonSalesOrderType();
            if ($orderTypeId) {
                $orderData['type_id'] = (int) $orderTypeId;
            }

            $salesRepId = $this->channelMappings->odooAmazonSalesRep();
            if ($salesRepId) {
                $orderData['user_id'] = (int) $salesRepId;
            }

            $currency    = $amazonOrder['OrderTotal']['CurrencyCode'] ?? '';
            $pricelistId = $currency
                ? $this->channelMappings->odooPricelist($currency, ChannelMapping::CHANNEL_AMAZON)
                : null;
            if ($pricelistId) {
                $orderData['pricelist_id'] = (int) $pricelistId;
            }

            $teamId = $this->channelMappings->resolve(
                ChannelMapping::TYPE_CHANNEL, ChannelMapping::CHANNEL_AMAZON, 'amazon'
            );
            if ($teamId) {
                $orderData['team_id'] = (int) $teamId;
            }

            // FIX: uses ErpInterface::createOrder() — not Odoo-specific
            $erpOrderId = $this->erp->createOrder($orderData);

            if (in_array($amazonOrder['OrderStatus'] ?? '', ['Unshipped', 'PartiallyShipped', 'Shipped'])) {
                $this->erp->confirmOrder($erpOrderId);
            }

            $this->mappings->upsert(self::ENTITY_ORDER, (string) $erpOrderId, $amazonOrderId, [
                'shopify_handle' => $amazonOrderId,
                'last_synced_at' => now(),
            ]);

            $log->markSuccess(json_encode(['erp_order_id' => $erpOrderId]));
            Log::info("Amazon order {$amazonOrderId} → {$this->erp->driverName()} #{$erpOrderId}");

            return $erpOrderId;
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage(), ['trace' => substr($e->getTraceAsString(), 0, 500)]);
            throw $e;
        }
    }

    private function resolveOrCreatePartner(array $amazonOrder): int
    {
        $address = $amazonOrder['ShippingAddress'] ?? [];
        $email   = $amazonOrder['BuyerInfo']['BuyerEmail'] ?? '';

        if ($email && !str_contains($email, 'marketplace.amazon')) {
            // FIX: uses ErpInterface::findCustomerByEmail()
            $existing = $this->erp->findCustomerByEmail($email);
            if ($existing) return $existing['id'];
        }

        $countryId = null;
        $stateId   = null;

        if (!empty($address['CountryCode'])) {
            // FIX: uses ErpInterface::resolveCountry() / resolveState()
            $countryId = $this->erp->resolveCountry($address['CountryCode']);
            if ($countryId && !empty($address['StateOrRegion'])) {
                $stateId = $this->erp->resolveState($countryId, $address['StateOrRegion']);
            }
        }

        $partnerData = [
            'name'          => $address['Name'] ?? ($amazonOrder['BuyerInfo']['BuyerName'] ?? 'Amazon Customer'),
            'email'         => (!empty($email) && !str_contains($email, 'marketplace.amazon')) ? $email : '',
            'street'        => $address['AddressLine1'] ?? '',
            'street2'       => $address['AddressLine2'] ?? '',
            'city'          => $address['City'] ?? '',
            'zip'           => $address['PostalCode'] ?? '',
            'phone'         => $address['Phone'] ?? '',
            'customer_rank' => 1,
        ];

        if ($countryId) $partnerData['country_id'] = $countryId;
        if ($stateId)   $partnerData['state_id']   = $stateId;

        // FIX: uses ErpInterface::createCustomer()
        return $this->erp->createCustomer($partnerData);
    }

    private function buildOrderLines(array $orderItems): array
    {
        $lines = [];

        foreach ($orderItems as $item) {
            $sku            = $item['SellerSKU'] ?? '';
            $variantMapping = $sku
                ? $this->mappings->findByShopifyId(AmazonProductSyncService::ENTITY_VARIANT, $sku)
                : null;

            $price = (float) ($item['ItemPrice']['Amount'] ?? 0);
            $qty   = (int)   ($item['QuantityOrdered'] ?? 1);

            $line = [0, 0, [
                'name'            => $item['Title'] ?? ($sku ?: 'Amazon Product'),
                'product_uom_qty' => $qty,
                'price_unit'      => $qty > 0 ? round($price / $qty, 4) : $price,
            ]];

            if ($variantMapping) {
                $line[2]['product_id'] = (int) $variantMapping->erp_id;
            }

            $taxIds = $this->resolveTaxIds($item['ItemTax'] ?? [], ChannelMapping::CHANNEL_AMAZON);
            if ($taxIds) {
                $line[2]['tax_id'] = [[6, 0, $taxIds]];
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function resolveTaxIds(mixed $itemTax, string $channel): array
    {
        if (empty($itemTax)) return [];

        $taxLines = isset($itemTax['Amount']) ? [$itemTax] : (array) $itemTax;
        $taxIds   = [];

        foreach ($taxLines as $taxLine) {
            $title = $taxLine['TaxCollectionModel'] ?? $taxLine['Type'] ?? 'Tax';
            $taxId = $this->channelMappings->odooAmazonTax($title);
            if ($taxId) {
                $taxIds[] = (int) $taxId;
            }
        }

        return array_unique($taxIds);
    }
}
