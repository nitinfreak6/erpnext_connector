<?php

namespace App\Services\Erp\Sap;

use App\Services\Erp\ErpInterface;

/**
 * SapErpAdapter — example skeleton for adding SAP as a second ERP.
 *
 * Steps to activate:
 *   1. Fill in each method below with your SAP API calls.
 *   2. Add the SAP credentials to config/sap.php and .env.
 *   3. Uncomment the 'sap' line in AppServiceProvider::bindErpDriver().
 *   4. Set ERP_DRIVER=sap in .env.
 *   5. Done — zero other files need to change.
 *
 * Tip: inject a SapService (your SAP HTTP/RFC client) via the constructor,
 * exactly the same way OdooErpAdapter injects OdooService.
 */
class SapErpAdapter implements ErpInterface
{
    public function __construct(
        // private readonly SapService $sap,
    ) {}

    // ── Products ─────────────────────────────────────────────────────────

    public function getProductsModifiedSince(string $writeDate): array
    {
        // Example: call SAP OData API or RFC BAPI_MATERIAL_GETLIST
        // return $this->sap->get('/sap/opu/odata/sap/API_PRODUCT_SRV/A_Product', [
        //     '$filter' => "LastChangeDateTime gt datetime'{$writeDate}'",
        // ]);
        throw new \RuntimeException('SapErpAdapter::getProductsModifiedSince() not implemented.');
    }

    public function getAllActiveProducts(int $offset = 0, int $limit = 100): array
    {
        throw new \RuntimeException('SapErpAdapter::getAllActiveProducts() not implemented.');
    }

    public function getProductById(int $erpId): ?array
    {
        throw new \RuntimeException('SapErpAdapter::getProductById() not implemented.');
    }

    public function getVariantsForProducts(array $productIds): array
    {
        // SAP may not have a "variant" concept — return an empty array or
        // map SAP batch/configuration items here.
        return [];
    }

    public function getAttributeValues(array $valueIds): array
    {
        return [];
    }

    public function getCategory(int $categoryId): ?array
    {
        throw new \RuntimeException('SapErpAdapter::getCategory() not implemented.');
    }

    // ── Inventory ────────────────────────────────────────────────────────

    public function getInventoryModifiedSince(string $writeDate, ?int $locationId = null): array
    {
        throw new \RuntimeException('SapErpAdapter::getInventoryModifiedSince() not implemented.');
    }

    public function getInventoryForProducts(array $productIds): array
    {
        throw new \RuntimeException('SapErpAdapter::getInventoryForProducts() not implemented.');
    }

    public function availableQty(array $quant): int
    {
        // SAP field names will differ — map them here
        return (int) max(0, ($quant['quantity'] ?? 0) - ($quant['reserved_quantity'] ?? 0));
    }

    public function updateInventoryLevel(array $payload): void
    {
        throw new \RuntimeException('SapErpAdapter::updateInventoryLevel() not implemented.');
    }

    public function resolveProductIdByReference(string $reference): ?int
    {
        throw new \RuntimeException('SapErpAdapter::resolveProductIdByReference() not implemented.');
    }

    // ── Orders ───────────────────────────────────────────────────────────

    public function getOrdersModifiedSince(string $writeDate): array
    {
        throw new \RuntimeException('SapErpAdapter::getOrdersModifiedSince() not implemented.');
    }

    public function getOrderLines(array $lineIds): array
    {
        throw new \RuntimeException('SapErpAdapter::getOrderLines() not implemented.');
    }

    public function getPickings(array $pickingIds): array
    {
        throw new \RuntimeException('SapErpAdapter::getPickings() not implemented.');
    }

    public function getMoves(array $moveIds): array
    {
        throw new \RuntimeException('SapErpAdapter::getMoves() not implemented.');
    }

    public function createOrder(array $orderData): int
    {
        throw new \RuntimeException('SapErpAdapter::createOrder() not implemented.');
    }

    public function confirmOrder(int $orderId): bool
    {
        throw new \RuntimeException('SapErpAdapter::confirmOrder() not implemented.');
    }

    public function cancelOrder(int $orderId): bool
    {
        throw new \RuntimeException('SapErpAdapter::cancelOrder() not implemented.');
    }

    // ── Customers ────────────────────────────────────────────────────────

    public function getCustomersModifiedSince(string $writeDate): array
    {
        throw new \RuntimeException('SapErpAdapter::getCustomersModifiedSince() not implemented.');
    }

    public function findCustomerByEmail(string $email): ?array
    {
        throw new \RuntimeException('SapErpAdapter::findCustomerByEmail() not implemented.');
    }

    public function createCustomer(array $data): int
    {
        throw new \RuntimeException('SapErpAdapter::createCustomer() not implemented.');
    }

    public function updateCustomer(int $customerId, array $data): bool
    {
        throw new \RuntimeException('SapErpAdapter::updateCustomer() not implemented.');
    }

    public function resolveCountry(string $iso2): ?int
    {
        throw new \RuntimeException('SapErpAdapter::resolveCountry() not implemented.');
    }

    public function resolveState(int $countryId, string $code): ?int
    {
        throw new \RuntimeException('SapErpAdapter::resolveState() not implemented.');
    }

    public function prepareProductWriteValue(string $field, mixed $value): mixed
    {
        return $value;
    }

    public function extractRelationId(mixed $value): int|string|null
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        return is_numeric($value) ? (is_int($value) ? $value : (int) $value) : (is_string($value) ? $value : null);
    }

    public function resolvePartnerReference(string $role, mixed $value): int|string|null
    {
        return is_scalar($value) && $value !== '' ? $value : null;
    }

    // ── Normalisation ────────────────────────────────────────────────────

    public function normalizeProduct(array $raw): array
    {
        // Map SAP field names to the canonical structure.
        // SAP material master uses different field names than Odoo.
        return [
            'erp_id'        => $raw['Material'] ?? $raw['id'],
            'name'          => $raw['MaterialDescription'] ?? $raw['name'] ?? '',
            'sku'           => $raw['Material'] ?? '',          // SAP material number is the SKU
            'barcode'       => $raw['EANNumber'] ?? '',
            'price'         => $raw['Price'] ?? 0,
            'cost'          => $raw['StandardPrice'] ?? 0,
            'weight'        => $raw['GrossWeight'] ?? 0,
            'category_id'   => $raw['MaterialGroup'] ?? null,
            'category_name' => $raw['MaterialGroupDescription'] ?? '',
            'description'   => $raw['LongText'] ?? '',
            'is_published'  => true,
            'meta_keywords' => '',
            'image_base64'  => '',
            'write_date'    => $raw['LastChangeDateTime'] ?? '',
            'active'        => ($raw['DeletionFlag'] ?? '') !== 'X',
            'sale_ok'       => true,
            '_raw'          => $raw,
        ];
    }

    public function normalizeVariant(array $raw): array
    {
        return [
            'erp_id'           => $raw['id'],
            'name'             => $raw['name'] ?? '',
            'sku'              => $raw['sku'] ?? '',
            'barcode'          => $raw['barcode'] ?? '',
            'price'            => $raw['price'] ?? 0,
            'cost'             => $raw['cost'] ?? 0,
            'weight'           => $raw['weight'] ?? 0,
            'template_erp_id'  => $raw['template_id'] ?? null,
            'active'           => true,
            'write_date'       => $raw['write_date'] ?? '',
            'product_template_attribute_value_ids' => [],
            '_raw'             => $raw,
        ];
    }

    public function normalizeCustomer(array $raw): array
    {
        return [
            'erp_id'      => $raw['BusinessPartner'] ?? $raw['id'],
            'name'        => $raw['BusinessPartnerFullName'] ?? '',
            'email'       => $raw['EmailAddress'] ?? '',
            'phone'       => $raw['PhoneNumber'] ?? '',
            'street'      => $raw['StreetName'] ?? '',
            'street2'     => $raw['HouseNumber'] ?? '',
            'city'        => $raw['CityName'] ?? '',
            'zip'         => $raw['PostalCode'] ?? '',
            'country_id'  => null,
            'country_code'=> $raw['Country'] ?? '',
            'state_id'    => null,
            'state_code'  => $raw['Region'] ?? '',
            'is_company'  => ($raw['BusinessPartnerCategory'] ?? '') === '2',
            'write_date'  => $raw['LastChangeDateTime'] ?? '',
            '_raw'        => $raw,
        ];
    }

    public function normalizeOrder(array $raw): array
    {
        return [
            'erp_id'      => $raw['SalesOrder'] ?? $raw['id'],
            'name'        => $raw['SalesOrder'] ?? '',
            'state'       => $raw['OverallSDProcessStatus'] ?? '',
            'origin'      => $raw['CustomerPurchaseOrderType'] ?? '',
            'partner_id'  => $raw['SoldToParty'] ?? null,
            'order_lines' => $raw['to_Item'] ?? [],
            'picking_ids' => [],
            'date_order'  => $raw['SalesOrderDate'] ?? '',
            'write_date'  => $raw['LastChangeDateTime'] ?? '',
            '_raw'        => $raw,
        ];
    }

    // ── Meta ─────────────────────────────────────────────────────────────

    public function driverName(): string
    {
        return 'sap';
    }

    /**
     * Field discovery — returns [] until the SAP adapter is implemented.
     * Returning [] (rather than throwing) lets the field-config menu render
     * empty instead of erroring while the adapter is being built out.
     */
    public function getAvailableFields(string $entityType): array
    {
        return [];
    }
}
