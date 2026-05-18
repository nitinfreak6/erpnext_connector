<?php

namespace App\Services\Odoo;

class OdooProductService
{
    private const TEMPLATE_FIELDS = [
        'id',
        'name',
        'default_code',
        'barcode',
        'list_price',
        'standard_price',
        'weight',
        'categ_id',
        'website_published',
        'description_sale',
        'website_meta_keywords',
        'image_1920',
        'attribute_line_ids',
        'qty_available',
        'virtual_available',
        'sale_ok',
        'active',
        'write_date',
    ];

    private const VARIANT_FIELDS = [
        'id',
        'name',
        'default_code',
        'barcode',
        'lst_price',
        'standard_price',
        'weight',
        'product_tmpl_id',
        'active',
        'write_date',
    ];

    public function __construct(private readonly OdooService $odoo) {}

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
            // Extract parent template IDs from modified variants
            $variantTemplateIds = array_map(
                fn($v) => is_array($v['product_tmpl_id']) ? $v['product_tmpl_id'][0] : $v['product_tmpl_id'],
                $modifiedVariants
            );

            // Merge with directly modified templates, deduplicate
            $templateIds = array_values(array_unique(
                array_merge($templateIds, $variantTemplateIds)
            ));
        }

        if (empty($templateIds)) {
            return [];
        }

        // ── 3. Fetch full template data for all affected IDs ─────────────
        // Filter out non-saleable templates that came in via variant path
        return $this->odoo->searchRead(
            'product.template',
            [
                ['id',      'in', $templateIds],
                ['active',  '=',  true],
                ['sale_ok', '=',  true],
            ],
            self::TEMPLATE_FIELDS,
            ['order' => 'write_date asc']
        );
    }

    /**
     * Get all active, saleable products (for full sync).
     */
    public function getAllActive(int $offset = 0, int $limit = 100): array
    {
        return $this->odoo->searchRead(
            'product.template',
            [['active', '=', true], ['sale_ok', '=', true]],
            self::TEMPLATE_FIELDS,
            ['order' => 'id asc', 'offset' => $offset, 'limit' => $limit]
        );
    }

    /**
     * Get attribute values for a product template as a key=>value map.
     */
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

    /**
     * Get variants for a list of template IDs.
     */
    public function getVariantsForTemplates(array $templateIds): array
    {
        return $this->odoo->searchRead(
            'product.product',
            [['product_tmpl_id', 'in', $templateIds], ['active', '=', true]],
            self::VARIANT_FIELDS
        );
    }

    /**
     * Get attribute values for variants.
     */
    public function getAttributeValues(array $valueIds): array
    {
        return $this->odoo->read(
            'product.template.attribute.value',
            $valueIds,
            ['id', 'name', 'attribute_id', 'product_attribute_value_id']
        );
    }

    /**
     * Get product categories.
     */
    public function getCategory(int $categId): ?array
    {
        $result = $this->odoo->read('product.category', [$categId], ['id', 'name', 'complete_name']);
        return $result[0] ?? null;
    }
}