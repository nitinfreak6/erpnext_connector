<?php

namespace App\Services\Odoo;

class OdooProductService
{
    /**
     * Minimum fields always fetched regardless of field config.
     * These are needed for cache bookkeeping, staleness checks, and
     * attribute/variant resolution — not for field mapping.
     */
    private const TEMPLATE_REQUIRED = [
        'id', 'name', 'active', 'sale_ok', 'write_date',
        'attribute_line_ids',   // needed to resolve variant options
        'categ_id',             // used by ProductCacheService for category display
    ];

    private const VARIANT_REQUIRED = [
        'id', 'name', 'active', 'write_date',
        'product_tmpl_id',                          // links variant → template
        'product_template_attribute_value_ids',     // needed for option1/2/3 resolution
    ];

    public function __construct(private readonly OdooService $odoo) {}

    // ─────────────────────────────────────────────────────────────────────
    // Dynamic field list builders
    //
    // Called by each public fetch method. Merges the required skeleton
    // fields with whatever erp_field values are active in field configs
    // so Odoo returns exactly what the mapping layer needs — nothing hardcoded.
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build the Odoo field list for product.template reads.
     * Merges required skeleton fields + all active erp_fields for
     * entity_type='product', scope='template'.
     */
    private function templateFields(): array
    {
        return $this->mergeWithRequired(
            self::TEMPLATE_REQUIRED,
            $this->configuredErpFields('template')
        );
    }

    /**
     * Build the Odoo field list for product.product reads.
     * Merges required skeleton fields + all active erp_fields for
     * entity_type='product', scope='variant'.
     */
    private function variantFields(): array
    {
        return $this->mergeWithRequired(
            self::VARIANT_REQUIRED,
            $this->configuredErpFields('variant')
        );
    }

    /**
     * Pull erp_field (and erp_field_2) root names from all active
     * product field configs for a given scope, for the current driver pair.
     * Uses the same cache that UniversalSyncService uses (300 s TTL).
     */
    private function configuredErpFields(string $scope): array
    {
        $sync = app(\App\Services\Sync\UniversalSyncService::class);
        return $sync->getErpFieldsToFetch('product', $scope);
    }

    private function mergeWithRequired(array $required, array $configured): array
    {
        return array_values(array_unique(array_merge($required, $configured)));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Full single-product fetch (used by detail/info page — no field filter)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Read EVERY field of a single product.template (no whitelist).
     * Used by the product detail/info page. Not used by bulk sync.
     */
    public function getByIdFull(int $id): ?array
    {
        $results = $this->odoo->read('product.template', [$id], []);
        return $results[0] ?? null;
    }

    public function getVendorsForTemplate(int $templateId): array
    {
        return $this->odoo->searchRead(
            'product.supplierinfo',
            [['product_tmpl_id', '=', $templateId]],
            ['id', 'partner_id', 'product_name', 'product_code',
             'min_qty', 'price', 'delay', 'currency_id', 'company_id'],
            ['order' => 'sequence asc, min_qty asc']
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Incremental sync
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Get all products modified since a given write_date.
     *
     * Checks BOTH product.template and product.product write_date because:
     *   - product.template.write_date updates when: name, description, category, image change
     *   - product.product.write_date updates when: price (lst_price), barcode, weight, SKU change
     *
     * Without the variant check, price edits in Odoo are silently missed.
     */
    public function getModifiedSince(string $writeDate): array
    {
        // ── 1. Templates modified directly ───────────────────────────────
        $templateIds = $this->odoo->search(
            'product.template',
            [
                ['write_date', '>', $writeDate],
                ['active',     '=', true],
                ['sale_ok',    '=', true],
            ]
        );

        // ── 2. Variants modified (price, barcode, SKU, weight changes) ───
        $modifiedVariants = $this->odoo->searchRead(
            'product.product',
            [
                ['write_date', '>', $writeDate],
                ['active',     '=', true],
            ],
            ['id', 'product_tmpl_id'],
            ['limit' => 1000]
        );

        if (!empty($modifiedVariants)) {
            $variantTemplateIds = array_map(
                fn($v) => is_array($v['product_tmpl_id']) ? $v['product_tmpl_id'][0] : $v['product_tmpl_id'],
                $modifiedVariants
            );
            $templateIds = array_values(array_unique(array_merge($templateIds, $variantTemplateIds)));
        }

        if (empty($templateIds)) {
            return [];
        }

        // ── 3. Fetch with config-driven field list ────────────────────────
        return $this->odoo->searchRead(
            'product.template',
            [
                ['id',      'in', $templateIds],
                ['active',  '=',  true],
                ['sale_ok', '=',  true],
            ],
            $this->templateFields(),
            ['order' => 'write_date asc']
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Single product fetch (lightweight — for staleness check)
    // ─────────────────────────────────────────────────────────────────────

    public function getById(int $id): ?array
    {
        $results = $this->odoo->searchRead(
            'product.template',
            [['id', '=', $id]],
            $this->templateFields(),
            ['limit' => 1]
        );
        return $results[0] ?? null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Full sync (paginated)
    // ─────────────────────────────────────────────────────────────────────

    public function getAllActive(int $offset = 0, int $limit = 100): array
    {
        return $this->odoo->searchRead(
            'product.template',
            [['active', '=', true], ['sale_ok', '=', true]],
            $this->templateFields(),
            ['order' => 'id asc', 'offset' => $offset, 'limit' => $limit]
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Variants
    // ─────────────────────────────────────────────────────────────────────

    public function getVariantsForTemplates(array $templateIds): array
    {
        return $this->odoo->searchRead(
            'product.product',
            [['product_tmpl_id', 'in', $templateIds], ['active', '=', true]],
            $this->variantFields()
        );
    }

    public function resolveTemplateIdForVariant(int $variantId): ?int
    {
        if ($variantId <= 0) {
            return null;
        }

        $rows = $this->odoo->read('product.product', [$variantId], ['product_tmpl_id']);
        if ($rows === []) {
            return null;
        }

        $raw = $rows[0]['product_tmpl_id'] ?? null;

        if (is_array($raw)) {
            return isset($raw[0]) ? (int) $raw[0] : null;
        }

        return $raw !== null && $raw !== '' ? (int) $raw : null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Attributes / options
    // ─────────────────────────────────────────────────────────────────────

    public function getProductAttributes(int $templateId): array
    {
        $lines = $this->odoo->searchRead(
            'product.template.attribute.line',
            [['product_tmpl_id', '=', $templateId]],
            ['attribute_id', 'value_ids']
        );

        if (empty($lines)) return [];

        $allValueIds = array_merge(...array_column($lines, 'value_ids'));
        if (empty($allValueIds)) return [];

        $values = $this->odoo->read(
            'product.attribute.value',
            $allValueIds,
            ['id', 'name', 'attribute_id']
        );

        $valuesById = [];
        foreach ($values as $v) {
            $valuesById[$v['id']] = $v['name'];
        }

        $result = [];
        foreach ($lines as $line) {
            $attrName     = strtolower($line['attribute_id'][1]);
            $firstValueId = $line['value_ids'][0] ?? null;
            if ($firstValueId && isset($valuesById[$firstValueId])) {
                $result[$attrName] = $valuesById[$firstValueId];
            }
        }

        return $result;
    }

    public function getAttributeValues(array $valueIds): array
    {
        return $this->odoo->read(
            'product.template.attribute.value',
            $valueIds,
            ['id', 'name', 'attribute_id', 'product_attribute_value_id']
        );
    }

    public function getCategory(int $categId): ?array
    {
        $result = $this->odoo->read('product.category', [$categId], ['id', 'name', 'complete_name']);
        return $result[0] ?? null;
    }
}