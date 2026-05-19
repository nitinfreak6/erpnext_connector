<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Models\ProductFieldConfig;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * ShopifyProductService
 *
 * ══════════════════════════════════════════════════════════════════════
 * DESIGN PRINCIPLE — config-driven, nothing hardcoded
 * ══════════════════════════════════════════════════════════════════════
 *
 * The shopify_field column in product_field_configs IS the GraphQL key.
 * This service reads those keys and routes/nests values purely by
 * key-pattern rules:
 *
 *   Key pattern                     │ Routed to
 *   ────────────────────────────────┼───────────────────────────────────
 *   'images'                        │ $media param (CreateMediaInput[])
 *   'status'                        │ ProductInput.status (uppercased)
 *   'tags'                          │ ProductInput.tags (string → array)
 *   dot-notation e.g. 'a.b.c'       │ deeply nested via dot-set
 *   option1 / option2 / option3     │ variant optionValues[]
 *   'inventoryPolicy'               │ variant top-level (uppercased enum)
 *   'taxable','requiresShipping'… . │ variant top-level (bool cast)
 *   'price','compareAtPrice'        │ variant top-level (2dp string)
 *   anything else                   │ passthrough as-is
 *
 * Adding a new Shopify field in the dashboard → works immediately.
 * Disabling a field config row → that field is omitted from the payload.
 * Deleting a row → field never sent to Shopify.
 *
 * This service is ERP-agnostic: it only cares about shopify_field keys
 * and the values resolved by resolveValue(). Swapping the ERP means
 * only the Odoo-side resolver changes, not this service.
 *
 * ══════════════════════════════════════════════════════════════════════
 * Routing reference (derived from key name — no lookup table)
 * ══════════════════════════════════════════════════════════════════════
 *
 * Template scope ProductInput keys:
 *   title, descriptionHtml, vendor, productType, tags, status,
 *   handle, templateSuffix, images
 *   → any future key added in the dashboard is passed through as-is
 *
 * Variant scope keys and their nesting:
 *   price, compareAtPrice           → variant (formatted to 2dp string)
 *   taxable                         → variant (bool)
 *   inventoryPolicy                 → variant (uppercased enum: DENY/CONTINUE)
 *   option1/2/3                     → variant.optionValues[]
 *   inventoryItem.*                 → variant.inventoryItem (nested)
 *     .sku                          → inventoryItem.sku
 *     .barcode                      → inventoryItem.barcode
 *     .tracked                      → inventoryItem.tracked (bool)
 *     .requiresShipping             → inventoryItem.requiresShipping (bool)
 *     .measurement.weight.value     → inventoryItem.measurement.weight.value
 *     .measurement.weight.unit      → inventoryItem.measurement.weight.unit (enum)
 *   any other dot key               → nested via dot-set
 *   anything else                   → passthrough
 */
class ShopifyProductService
{
    // Structural keys never sent as simple k→v pairs to ProductInput
    private const STRUCTURAL = ['images', 'variants', 'options'];

    // Variant keys that stay at top level (not nested under inventoryItem)
    private const VARIANT_TOP_LEVEL = ['price', 'compareAtPrice', 'taxable', 'inventoryPolicy'];

    // Weight unit → GraphQL enum
    private const WEIGHT_UNITS = [
        'kg' => 'KILOGRAMS', 'g'  => 'GRAMS', 'lb' => 'POUNDS', 'oz' => 'OUNCES',
        'KILOGRAMS' => 'KILOGRAMS', 'GRAMS' => 'GRAMS', 'POUNDS' => 'POUNDS', 'OUNCES' => 'OUNCES',
    ];

    // Inventory policy → GraphQL enum
    private const INVENTORY_POLICIES = [
        'deny' => 'DENY', 'continue' => 'CONTINUE', 'DENY' => 'DENY', 'CONTINUE' => 'CONTINUE',
    ];
	
	private const INVENTORY_SCHEMA = [
		'sku',
		'tracked',
		'requiresShipping',
		'measurement.weight.value',
		'measurement.weight.unit',
	];

    public function __construct(private readonly ShopifyGraphQLService $graphql) {}

    // ══════════════════════════════════════════════════════════════════════
    // LIST / FETCH PRODUCTS
    // ══════════════════════════════════════════════════════════════════════

    /**
     * List products from Shopify with optional filters
     * 
     * @param array $filters ['limit' => 50, 'updated_at_min' => '2024-01-01', 'status' => 'active']
     * @return array Array of products
     */
    public function list(array $filters = []): array
    {
        $limit = $filters['limit'] ?? 250;
        $updatedAtMin = $filters['updated_at_min'] ?? null;
        $status = $filters['status'] ?? null;
        
        // Build query string for filtering
        $queryParts = [];
        if ($updatedAtMin) {
            $queryParts[] = "updated_at:>'{$updatedAtMin}'";
        }
        if ($status) {
            $queryParts[] = "status:{$status}";
        }
        $queryString = !empty($queryParts) ? implode(' AND ', $queryParts) : null;

        // Use different query based on whether we have filters
        if ($queryString) {
            $query = $this->getProductsQueryWithFilter();
        } else {
            $query = $this->getProductsQueryNoFilter();
        }

        Log::info('Fetching products from Shopify', [
            'limit' => $limit,
            'query_string' => $queryString,
            'filters' => $filters,
        ]);

        $products = [];
        $hasNextPage = true;
        $cursor = null;
        $fetchedCount = 0;

        while ($hasNextPage && $fetchedCount < $limit) {
            if ($queryString) {
                $variables = [
                    'first' => min(50, $limit - $fetchedCount),
                    'query' => $queryString,
                    'after' => $cursor,
                ];
            } else {
                $variables = [
                    'first' => min(50, $limit - $fetchedCount),
                    'after' => $cursor,
                ];
            }

            $response = $this->graphql->query($query, $variables);
            
            Log::debug('Shopify GraphQL response', [
                'has_products' => isset($response['products']),
                'edges_count' => count($response['products']['edges'] ?? []),
            ]);
            
            if (!isset($response['products'])) {
                Log::error('Shopify product list query failed', ['response' => $response]);
                break;
            }

            $edges = $response['products']['edges'] ?? [];
            
            foreach ($edges as $edge) {
                $product = $this->normalizeProduct($edge['node']);
                $products[] = $product;
                $fetchedCount++;
                
                if ($fetchedCount >= $limit) {
                    break;
                }
            }

            $pageInfo = $response['data']['products']['pageInfo'] ?? [];
            $hasNextPage = $pageInfo['hasNextPage'] ?? false;
            $cursor = $pageInfo['endCursor'] ?? null;
        }

        return $products;
    }

    private function getProductsQueryNoFilter(): string
    {
        return <<<'GRAPHQL'
        query($first: Int!, $after: String) {
          products(first: $first, after: $after) {
            edges {
              node {
                id
                title
                descriptionHtml
                handle
                status
                vendor
                productType
                tags
                createdAt
                updatedAt
                variants(first: 100) {
                  edges {
                    node {
                      id
                      title
                      sku
                      barcode
                      price
                      compareAtPrice
                      inventoryQuantity
                      taxable
                      inventoryPolicy
                      inventoryItem {
                        id
                      }
                    }
                  }
                }
                images(first: 10) {
                  edges {
                    node {
                      id
                      url
                      altText
                    }
                  }
                }
              }
            }
            pageInfo {
              hasNextPage
              endCursor
            }
          }
        }
        GRAPHQL;
    }

    private function getProductsQueryWithFilter(): string
    {
        return <<<'GRAPHQL'
        query($first: Int!, $query: String!, $after: String) {
          products(first: $first, query: $query, after: $after) {
            edges {
              node {
                id
                title
                descriptionHtml
                handle
                status
                vendor
                productType
                tags
                createdAt
                updatedAt
                variants(first: 100) {
                  edges {
                    node {
                      id
                      title
                      sku
                      barcode
                      price
                      compareAtPrice
                      inventoryQuantity
                      taxable
                      inventoryPolicy
                      inventoryItem {
                        id
                      }
                    }
                  }
                }
                images(first: 10) {
                  edges {
                    node {
                      id
                      url
                      altText
                    }
                  }
                }
              }
            }
            pageInfo {
              hasNextPage
              endCursor
            }
          }
        }
        GRAPHQL;
    }

    /**
     * Normalize GraphQL product response to simpler array structure
     * Uses the existing normalizeProduct at the end of the class
     */

    // ══════════════════════════════════════════════════════════════════════
    // CREATE / UPDATE
    // ══════════════════════════════════════════════════════════════════════


    // ─────────────────────────────────────────────────────────────────────
    // Public API  (same signatures as old REST service — callers unchanged)
    // ─────────────────────────────────────────────────────────────────────

    public function create(array $productData): array
    {
        [$input, $media] = $this->toGraphQLInput($productData);

        $data   = $this->graphql->query($this->productMutation('productCreate'), [
            'input' => $input, 'media' => $media ?: null,
        ]);
        $errors = $this->graphql->extractUserErrors($data, 'productCreate');

        if (!empty($errors)) {
            throw new ShopifyApiException('Shopify productCreate errors: ' . implode('; ', $errors), 422, 'productCreate');
        }
		
		$product = $data['productCreate']['product'];
		$productId = $this->fromGid($product['id']);

		$this->syncVariants($product['id'], $productData['variants'] ?? []);

		return $this->normalizeProduct($product);

        //return $this->normalizeProduct($data['productCreate']['product']);
    }

    public function update(string $shopifyProductId, array $productData): array
    {
        [$input, $media] = $this->toGraphQLInput($productData);
        $input['id']     = $this->toGid('Product', $shopifyProductId);

        $data   = $this->graphql->query($this->productMutation('productUpdate'), [
            'input' => $input, 'media' => $media ?: null,
        ]);
        $errors = $this->graphql->extractUserErrors($data, 'productUpdate');

        if (!empty($errors)) {
            $notFound = array_filter($errors, fn($e) =>
                stripos($e, 'not found') !== false || stripos($e, 'does not exist') !== false
            );
            throw new ShopifyApiException(
                'Shopify productUpdate errors: ' . implode('; ', $errors),
                !empty($notFound) ? 404 : 422,
                'productUpdate'
            );
        }
		
		$product = $data['productUpdate']['product'];

		$this->syncVariants($product['id'], $productData['variants'] ?? []);

		return $this->normalizeProduct($product);

        //return $this->normalizeProduct($data['productUpdate']['product']);
    }
	
	private function getExistingVariants(string $productGid): array
	{
		$query = <<<GQL
		query(\$id: ID!) {
		  product(id: \$id) {
			variants(first: 10) {
			  edges {
				node { id title }
			  }
			}
		  }
		}
		GQL;

		$data = $this->graphql->query($query, ['id' => $productGid]);

		return array_map(
			fn($e) => $e['node'],
			$data['product']['variants']['edges'] ?? []
		);
	}
	
	private function replaceDefaultVariant(string $productGid, string $variantGid, array $payload): void
	{
		$mutation = <<<GQL
		mutation(\$productId: ID!, \$variants: [ProductVariantsBulkInput!]!) {
		  productVariantsBulkUpdate(
			productId: \$productId,
			variants: \$variants
		  ) {
			userErrors { field message }
		  }
		}
		GQL;

		$input = $this->toGraphQLVariantInput($payload);
		$input['id'] = $variantGid;

		$this->graphql->query($mutation, [
			'productId' => $productGid,
			'variants'  => [$input],
		]);
	}
	
	private function syncVariants(string $productGid, array $variants): void
	{
		if (empty($variants)) return;
		
		$existing = $this->getExistingVariants($productGid);

		if (count($existing) === 1 && $existing[0]['title'] === 'Default Title') {
			$this->replaceDefaultVariant(
				$productGid,
				$existing[0]['id'],
				$variants[0]
			);
			return;
		}

		$mutation = <<<GQL
		mutation bulkCreateVariants(\$productId: ID!, \$variants: [ProductVariantsBulkInput!]!) {
			productVariantsBulkCreate(productId: \$productId, variants: \$variants) {
				productVariants { id }
				userErrors { field message }
			}
		}
		GQL;

		$variantsInput = array_map(
			fn($v) => $this->toGraphQLVariantInput($v),
			$variants
		);

		$data = $this->graphql->query($mutation, [
			'productId' => $productGid,
			'variants'  => $variantsInput,
		]);

		$errors = $this->graphql->extractUserErrors($data, 'productVariantsBulkCreate');
		if (!empty($errors)) {
			throw new ShopifyApiException(
				'Variant sync failed: ' . implode('; ', $errors),
				422,
				'productVariantsBulkCreate'
			);
		}
	}

    public function get(string $shopifyProductId): ?array
    {
        try {
            $query = <<<'GQL'
            query getProduct($id: ID!) {
                product(id: $id) {
                    id title handle status
                    variants(first: 100) {
                        edges { node { id sku price compareAtPrice inventoryItem { id } } }
                    }
                }
            }
            GQL;

            $data    = $this->graphql->query($query, ['id' => $this->toGid('Product', $shopifyProductId)]);
            $product = $data['product'] ?? null;

            return $product ? $this->normalizeProduct($product) : null;
        } catch (\Throwable $e) {
            Log::warning("ShopifyProductService::get failed #{$shopifyProductId}: " . $e->getMessage());
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // buildPayload — Odoo data → intermediate payload
    //
    // Keys in this array ARE the shopify_field values from product_field_configs.
    // toGraphQLInput() then routes them into the correct GraphQL structure.
    // ─────────────────────────────────────────────────────────────────────

    public function buildPayload(array $odooTemplate, array $variants, array $attributeValues): array
    {
        $configs         = $this->getFieldConfigs();
        $templateConfigs = array_filter($configs, fn($c) => $c['scope'] === 'template');
        $variantConfigs  = array_filter($configs, fn($c) => $c['scope'] === 'variant');

        $payload = [];

        foreach ($templateConfigs as $config) {
            $key = $config['shopify_field'];

            if (!$config['is_active']) {
                // Inactive → omit entirely (field will not be sent to Shopify)
                // Sending null/empty for unknown future fields could cause errors
                continue;
            }

            $value = $this->resolveValue($odooTemplate, $config);
            if ($value === null || $value === '') continue;

            $payload[$key] = $value;
        }

        // status must always be present
        if (!isset($payload['status'])) {
            $payload['status'] = 'draft';
        }

        // Category override from ProductSyncService
        if (!empty($odooTemplate['_shopify_product_type'])) {
            $payload['productType'] = $odooTemplate['_shopify_product_type'];
        }

        // Variants
        $shopifyVariants = array_map(
            fn($v) => $this->buildVariantPayload($v, $attributeValues, $variantConfigs),
            $variants
        );
        $payload['variants'] = $shopifyVariants;

        // Options
        if (!empty($odooTemplate['attribute_line_ids'])) {
            $options = $this->buildOptions($attributeValues, $shopifyVariants);
            if (!empty($options)) {
                $payload['options'] = $options;
            }
        }

        return $payload;
    }

    // ─────────────────────────────────────────────────────────────────────
    // toGraphQLInput — routes shopify_field keys into GraphQL ProductInput
    //
    // No field name lookup tables. Routing is by key-pattern rules only.
    // ─────────────────────────────────────────────────────────────────────

    private function toGraphQLInput(array $payload): array
    {
        $input = [];
        $media = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, self::STRUCTURAL, true)) continue;

            $this->applyTemplateKey($input, $key, $value);
        }

        // images → CreateMediaInput[]
        if (!empty($payload['images'])) {
            foreach ((array)$payload['images'] as $img) {
				if (!empty($img['attachment'])) {
					$media[] = [
						'mediaContentType' => 'IMAGE',
						'originalSource'   => $this->makePublicImageUrl($img['attachment']),
					];
				} elseif (!empty($img['src'])) {
					$media[] = [
						'mediaContentType' => 'IMAGE',
						'originalSource'   => $img['src'],
					];
				}
			}
        }

        // options → productOptions [{name, values:[{name}]}]
        if (!empty($payload['options'])) {
            $input['productOptions'] = array_map(fn($o) => [
                'name'   => $o['name'],
                'values' => array_map(fn($v) => ['name' => $v], (array)($o['values'] ?? [])),
            ], $payload['options']);
        }

        // variants
        // if (!empty($payload['variants'])) {
            // $input['variants'] = array_map(fn($v) => $this->toGraphQLVariantInput($v), $payload['variants']);
        // }

        return [$input, $media];
    }
	
	private function makePublicImageUrl(string $base64): string
	{
		// Already URL
		if (filter_var($base64, FILTER_VALIDATE_URL)) {
			return $base64;
		}

		// Raw base64 from Odoo
		$path = 'shopify_images/' . uniqid() . '.jpg';

		\Storage::disk('public')->put($path, base64_decode($base64));

		return asset('storage/' . $path);
	}

    /**
     * Route one template-scope key into ProductInput.
     *
     * Rules (checked in order):
     *  1. 'status'       → strtoupper  (enum: ACTIVE / DRAFT / ARCHIVED)
     *  2. 'tags'         → string → array
     *  3. dot.notation   → deeply nested via dotSet()
     *  4. everything else → passthrough (title, descriptionHtml, vendor, etc.)
     */
    private function applyTemplateKey(array &$input, string $key, mixed $value): void
    {
        switch ($key) {
            case 'status':
                $input['status'] = strtoupper((string)$value);
                return;

            case 'tags':
                $input['tags'] = is_array($value)
                    ? $value
                    : array_values(array_filter(array_map('trim', explode(',', (string)$value))));
                return;
        }

        if (str_contains($key, '.')) {
            $this->dotSet($input, $key, $value);
            return;
        }

        $input[$key] = $value;
    }

    /**
     * Route one variant payload row into GraphQL ProductVariantInput.
     *
     * Rules:
     *  1. option1/2/3           → optionValues[]
     *  2. inventoryPolicy       → top-level, uppercased enum
     *  3. price / compareAtPrice→ top-level, formatted to 2dp string
     *  4. taxable               → top-level, bool
     *  5. inventoryItem.*       → nested under inventoryItem
     *  6. dot-notation          → nested via dotSet()
     *  7. everything else       → passthrough
     */
    private function toGraphQLVariantInput(array $variantPayload): array
    {
        $variant       = [];
        $inventoryItem = [];
        $optionValues  = [];

        foreach ($variantPayload as $key => $value) {
            if ($value === null || $value === '') continue;

            // ── option1/2/3 ──────────────────────────────────────────────
            if (in_array($key, ['option1', 'option2', 'option3'], true)) {
                $pos = (int)substr($key, -1);
                $optionValues[$pos] = ['name' => "Option {$pos}", 'value' => (string)$value];
                continue;
            }

            // ── inventoryPolicy enum ─────────────────────────────────────
            if ($key === 'inventoryPolicy') {
                $variant['inventoryPolicy'] =
                    self::INVENTORY_POLICIES[strtoupper((string)$value)]
                    ?? self::INVENTORY_POLICIES[strtolower((string)$value)]
                    ?? 'DENY';
                continue;
            }

            // ── price / compareAtPrice ───────────────────────────────────
            if (in_array($key, ['price', 'compareAtPrice'], true)) {
                $variant[$key] = number_format((float)$value, 2, '.', '');
                continue;
            }

            // ── taxable ──────────────────────────────────────────────────
            if ($key === 'taxable') {
                $variant['taxable'] = (bool)$value;
                continue;
            }

            // ── inventoryItem.* ──────────────────────────────────────────
            if (str_starts_with($key, 'inventoryItem.')) {
				$subKey = substr($key, strlen('inventoryItem.'));

				if ($this->isValidInventoryKey($subKey)) {
					$this->applyInventorySubKey($inventoryItem, $subKey, $value);
				} else {
					// auto-promote to variant level (no hardcoding)
					$variant[$subKey] = $value;
				}
				continue;
			}

            // ── other dot-notation (future deep keys) ────────────────────
            if (str_contains($key, '.')) {
                $this->dotSet($variant, $key, $value);
                continue;
            }

            // ── passthrough (future top-level variant fields) ────────────
            $variant[$key] = $value;
        }
		
		// ── Shopify requires full weight object if measurement.weight is present ──
		if (
			isset($inventoryItem['measurement']['weight']['value']) &&
			!isset($inventoryItem['measurement']['weight']['unit'])
		) {
			// Default from config mindset — safe fallback
			$inventoryItem['measurement']['weight']['unit'] = 'KILOGRAMS';
		}

		if (
			isset($inventoryItem['measurement']['weight']['unit']) &&
			!isset($inventoryItem['measurement']['weight']['value'])
		) {
			unset($inventoryItem['measurement']['weight']); // drop incomplete object
		}

        if (!empty($inventoryItem)) {
            $variant['inventoryItem'] = $inventoryItem;
        }

        if (!empty($optionValues)) {
            ksort($optionValues);
            $variant['optionValues'] = array_values($optionValues);
        }

        return $variant;
    }
	
	private function isValidInventoryKey(string $subKey): bool
	{
		return in_array($subKey, self::INVENTORY_SCHEMA, true);
	}

    /**
     * Apply an inventoryItem sub-key (after stripping 'inventoryItem.' prefix).
     *
     * Sub-keys and their handling:
     *   tracked                  → bool
     *   requiresShipping         → bool
     *   measurement.weight.unit  → enum via WEIGHT_UNITS
     *   measurement.weight.value → float
     *   sku, barcode             → passthrough string
     *   any dot sub-key          → nested via dotSet()
     *   anything else            → passthrough
     */
    private function applyInventorySubKey(array &$inventoryItem, string $subKey, mixed $value): void
    {
        switch ($subKey) {
            case 'tracked':
                // Also accepts legacy 'shopify' string from old REST configs
                $inventoryItem['tracked'] = is_bool($value)
                    ? $value
                    : strtolower((string)$value) === 'shopify' || (bool)$value;
                return;

            case 'requiresShipping':
                $inventoryItem['requiresShipping'] = (bool)$value;
                return;

            case 'measurement.weight.unit':
                $unit = self::WEIGHT_UNITS[strtoupper((string)$value)]
                     ?? self::WEIGHT_UNITS[strtolower((string)$value)]
                     ?? 'KILOGRAMS';
                $this->dotSet($inventoryItem, 'measurement.weight.unit', $unit);
                return;

            case 'measurement.weight.value':
                $this->dotSet($inventoryItem, 'measurement.weight.value', (float)$value);
                return;
        }

        // dot sub-nesting or passthrough (sku, barcode, etc.)
        if (str_contains($subKey, '.')) {
            $this->dotSet($inventoryItem, $subKey, $value);
        } else {
            $inventoryItem[$subKey] = $value;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Variant payload builder
    // ─────────────────────────────────────────────────────────────────────

    private function buildVariantPayload(array $variant, array $attributeValues, array $variantConfigs): array
    {
        $avMap = array_column($attributeValues, null, 'id');
        $avIds = $variant['product_template_attribute_value_ids'] ?? [];
        $out   = [];

        foreach ($variantConfigs as $config) {
            if (!$config['is_active']) continue; // inactive → omit

            $value = $this->resolveValue($variant, $config);
            if ($value === null) continue;

            $out[$config['shopify_field']] = $value;
        }

        // Attribute values → option1/2/3
        foreach (array_slice($avIds, 0, 3) as $index => $avId) {
            $av = $avMap[$avId] ?? null;
            if ($av) {
                $out['option' . ($index + 1)] = $av['_mapped_name'] ?? $av['name'];
            }
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Options builder
    // ─────────────────────────────────────────────────────────────────────

    private function buildOptions(array $attributeValues, array $variants): array
    {
        $options = []; $seen = [];

        foreach ($variants as $variant) {
            foreach (['option1', 'option2', 'option3'] as $i => $k) {
                if (!empty($variant[$k]) && !isset($seen[$i])) {
                    $seen[$i] = true;
                    $options[] = ['name' => 'Option ' . ($i + 1), 'values' => []];
                }
            }
        }

        foreach ($variants as $variant) {
            foreach (['option1', 'option2', 'option3'] as $i => $k) {
                if (isset($options[$i]) && !empty($variant[$k])
                    && !in_array($variant[$k], $options[$i]['values'])) {
                    $options[$i]['values'][] = $variant[$k];
                }
            }
        }

        return array_values(array_filter($options, fn($o) => !empty($o['values'])));
    }

    // ─────────────────────────────────────────────────────────────────────
    // GQL mutation strings
    // ─────────────────────────────────────────────────────────────────────

    private function productMutation(string $name): string
    {
        return <<<GQL
        mutation {$name}(\$input: ProductInput!, \$media: [CreateMediaInput!]) {
            {$name}(input: \$input, media: \$media) {
                product {
                    id title handle status
                    variants(first: 100) {
                        edges { node { id sku price compareAtPrice inventoryItem { id } } }
                    }
                }
                userErrors { field message }
            }
        }
        GQL;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Response normalizer — GQL response → REST-like shape for callers
    // ─────────────────────────────────────────────────────────────────────

    private function normalizeProduct(array $p): array
    {
        $variants = array_map(function ($edge) {
            $v = $edge['node'];
            return [
                'id'                => $this->fromGid($v['id']),
                'sku'               => $v['sku']            ?? '',
                'price'             => $v['price']          ?? '0.00',
                'compare_at_price'  => $v['compareAtPrice'] ?? null,
                'inventory_item_id' => isset($v['inventoryItem']['id'])
                    ? $this->fromGid($v['inventoryItem']['id']) : null,
            ];
        }, $p['variants']['edges'] ?? []);

        return [
            'id'       => $this->fromGid($p['id']),
            'title'    => $p['title']  ?? '',
            'handle'   => $p['handle'] ?? '',
            'status'   => strtolower($p['status'] ?? 'draft'),
            'variants' => $variants,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // GID helpers
    // ─────────────────────────────────────────────────────────────────────

    public function toGid(string $type, string $id): string
    {
        if (str_starts_with($id, 'gid://')) return $id;
        return "gid://shopify/{$type}/{$id}";
    }

    public function fromGid(string $gid): string
    {
        return (string) last(explode('/', $gid));
    }

    // ─────────────────────────────────────────────────────────────────────
    // Dot-notation setter
    // dotSet($arr, 'a.b.c', 1)  →  $arr['a']['b']['c'] = 1
    // ─────────────────────────────────────────────────────────────────────

    private function dotSet(array &$target, string $key, mixed $value): void
    {
        $parts   = explode('.', $key);
        $current = &$target;
        foreach ($parts as $part) {
            if (!isset($current[$part]) || !is_array($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }
        $current = $value;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Field config loader
    // ─────────────────────────────────────────────────────────────────────

    private function getFieldConfigs(): array
    {
        return Cache::remember('product_field_configs_shopify', 60, function () {
            return ProductFieldConfig::where('channel', 'shopify')
                ->orderBy('sort_order')->orderBy('id')
                ->get()
                ->map(fn($c) => [
                    'shopify_field'     => $c->shopify_field,
                    'field_type'        => $c->field_type,
                    'odoo_field'        => $c->odoo_field,
                    'odoo_field_2'      => $c->odoo_field_2,
                    'combine_separator' => $c->combine_separator ?? ' ',
                    'scope'             => $c->scope,
                    'default_value'     => $c->default_value,
                    'transform'         => $c->transform,
                    'min_length'        => $c->min_length,
                    'max_length'        => $c->max_length,
                    'is_active'         => (bool)$c->is_active,
                ])
                ->toArray();
        });
    }

    // ─────────────────────────────────────────────────────────────────────
    // Value resolvers (Odoo → intermediate value)
    // ─────────────────────────────────────────────────────────────────────

    private function resolveValue(array $odooData, array $config): mixed
    {
        if ($config['field_type'] === 'custom') {
            return $config['default_value'] ?? null;
        }

        if ($config['field_type'] === 'combine') {
            $val1  = $this->readOdooField($odooData, $config['odoo_field']   ?? '');
            $val2  = $this->readOdooField($odooData, $config['odoo_field_2'] ?? '');
            $val1  = ($val1 === false) ? '' : (string)($val1 ?? '');
            $val2  = ($val2 === false) ? '' : (string)($val2 ?? '');
            $sep   = $config['combine_separator'] ?? ' ';
            $value = trim($val1 . ($val1 && $val2 ? $sep : '') . $val2);
            if ($value === '') $value = $config['default_value'] ?? null;
            return $this->applyLengthConstraints($value, $config);
        }

        $raw = $this->readOdooField($odooData, $config['odoo_field'] ?? '');
        if ($raw === false) $raw = null;

        $value = $this->applyTransform($raw, $config['transform'], $odooData);
        if ($value === null || $value === false || $value === '') {
            $value = $config['default_value'] ?? null;
        }

        return $this->applyLengthConstraints($value, $config);
    }

    private function readOdooField(array $data, string $key): mixed
    {
        if ($key === '') return null;

        if (str_contains($key, '.')) {
            [$parent, $index] = explode('.', $key, 2);
            $parent = $data[$parent] ?? null;
            return is_array($parent) ? ($parent[(int)$index] ?? null) : null;
        }

        return $data[$key] ?? null;
    }

    private function applyTransform(mixed $value, ?string $transform, array $context = []): mixed
    {
        return match ($transform) {
            'number_format'          => number_format((float)($value ?? 0), 2, '.', ''),
            'number_format_nullable' => ($value > 0) ? number_format((float)$value, 2, '.', '') : null,
            'boolean_status'         => (!empty($value) || !empty($context['website_published']) || !empty($context['is_published'])) ? 'active' : 'draft',
            'array_second'           => is_array($value) ? ($value[1] ?? null) : $value,
            'base64_image'           => !empty($value) ? [['attachment' => $value]] : null,
            default                  => $value,
        };
    }

    private function applyLengthConstraints(mixed $value, array $config): mixed
    {
        if (!is_string($value)) return $value;
        if ($config['min_length'] && strlen($value) < $config['min_length']) return null;
        if ($config['max_length'] && strlen($value) > $config['max_length']) {
            $value = substr($value, 0, $config['max_length']);
        }
        return $value;
    }
}