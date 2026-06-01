<?php

namespace App\Services\Odoo;

class OdooInventoryService
{
    private const QUANT_FIELDS = [
        'id', 'product_id', 'location_id', 'quantity', 'reserved_quantity', 'write_date',
    ];

    public function __construct(private readonly OdooService $odoo) {}

    /**
     * Get inventory quants modified since write_date, filtered to internal locations only.
     */
    public function getModifiedSince(string $writeDate, ?int $locationId = null): array
    {
        $domain = [
            ['write_date', '>=', $writeDate],
            ['location_id.usage', '=', 'internal'],
        ];

        if ($locationId) {
            $domain[] = ['location_id', '=', $locationId];
        }

        return $this->odoo->searchRead(
            'stock.quant',
            $domain,
            self::QUANT_FIELDS,
            ['order' => 'write_date asc', 'limit' => 1000]
        );
    }

    /**
     * Get all quants for active products (full sync).
     */
    public function getAllForProducts(array $productIds): array
    {
        return $this->odoo->searchRead(
            'stock.quant',
            [
                ['product_id', 'in', $productIds],
                ['location_id.usage', '=', 'internal'],
            ],
            self::QUANT_FIELDS
        );
    }

    /**
     * Calculate available qty = quantity - reserved_quantity.
     */
    public function availableQty(array $quant): int
    {
        return (int) max(0, ($quant['quantity'] ?? 0) - ($quant['reserved_quantity'] ?? 0));
    }
}