<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Services\Config\NestedFieldResolver;
use App\Services\FieldMappingService;
use App\Services\SettingsService;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;

/**
 * ShopifyProductService
 *
 * Config-driven product push: field configs use GraphQL paths like product.vendor,
 * product.metafields.0.key, media.0.originalSource — not hardcoded mutation shapes.
 *
 * Shopify API glue (not field mapping):
 *   - STRUCTURAL keys: variants, options, images orchestration
 *   - productOptionsCreate before variant optionValues
 *   - resolveVariantOptionIds (optionId lookup — paths from field config)
 *   - pruneEmptyNestedArrays (Shopify rejects empty nested objects)
 *   - pruneEmptyMeasurement (omit incomplete weight; default unit when value > 0)
 */
class ShopifyProductService
{
    /** Internal keys — variant/options sync, not GraphQL mutation variables */
    private const STRUCTURAL = ['images', 'variants', 'options'];

    /** Top-level GraphQL mutation argument names (from field config paths) */
    private const MUTATION_ROOTS = ['product', 'media'];

    /** ProductVariantsBulkInput keys set by sync orchestration (not erp field configs) */
    private const VARIANT_BULK_STRUCTURAL = ['id', 'optionValues', 'inventoryQuantities'];
	
	private array $wireLog = [];

    public function __construct(
        private readonly ShopifyGraphQLService $graphql,
        private readonly FieldMappingService $fieldMapping,
        private readonly NestedFieldResolver $fields,
        private readonly SettingsService $settings,
    ) {}

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

            $pageInfo = $response['products']['pageInfo'] ?? [];
            $hasNextPage = $pageInfo['hasNextPage'] ?? false;
            $cursor = $pageInfo['endCursor'] ?? null;
        }

        return $products;
    }

    private function productFragment(): string
    {
        return $this->fieldMapping->buildShopifyProductGraphqlFragment(
            $this->settings->ecomDriver(),
            $this->settings->erpDriver(),
        );
    }

    private function getProductsQueryNoFilter(): string
    {
        $fragment = $this->productFragment();

        return <<<GRAPHQL
        query(\$first: Int!, \$after: String) {
          products(first: \$first, after: \$after) {
            edges {
              node {
                ...ProductFields
              }
            }
            pageInfo {
              hasNextPage
              endCursor
            }
          }
        }
        {$fragment}
        GRAPHQL;
    }

    private function getProductsQueryWithFilter(): string
    {
        $fragment = $this->productFragment();

        return <<<GRAPHQL
        query(\$first: Int!, \$query: String!, \$after: String) {
          products(first: \$first, query: \$query, after: \$after) {
            edges {
              node {
                ...ProductFields
              }
            }
            pageInfo {
              hasNextPage
              endCursor
            }
          }
        }
        {$fragment}
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

    /** @var array<string, mixed> Product payload for the current create/update call. */
    private array $activeProductPayload = [];

    public function create(array $productData): array
    {
        $this->activeProductPayload = $productData;
        $variables = $this->buildGraphQLVariables($productData);

		$query = $this->productCreateMutation();
		$this->recordWire('productCreate', $query, $variables);
		$data   = $this->graphql->query($query, $variables);
		$this->recordResponse($data['productCreate'] ?? $data);

        $errors = $this->graphql->extractUserErrors($data, 'productCreate');

        if (!empty($errors)) {
            throw new ShopifyApiException('Shopify productCreate errors: ' . implode('; ', $errors), 422, 'productCreate');
        }
		
		$product = $data['productCreate']['product'];
		$productId = $this->fromGid($product['id']);

		$this->syncVariants($product['id'], $productData['variants'] ?? [], $productData['options'] ?? []);

		return $this->normalizeProduct($product);

        //return $this->normalizeProduct($data['productCreate']['product']);
    }

    public function update(string $shopifyProductId, array $productData): array
    {
        $this->activeProductPayload = $productData;
        // productUpdate rejects productOptions — options are synced via productOptionsCreate in syncVariants()
        $variables = $this->buildGraphQLVariables(
            $productData,
            includeProductOptions: false,
            productGid: $shopifyProductId
        );

		$query = $this->productUpdateMutation();
		$this->recordWire('productUpdate', $query, $variables);

		$data = $this->graphql->query($query, $variables);
		$this->recordResponse($data['productUpdate'] ?? $data);

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

		$this->syncVariants($product['id'], $productData['variants'] ?? [], $productData['options'] ?? []);

		return $this->normalizeProduct($product);

        //return $this->normalizeProduct($data['productUpdate']['product']);
    }
	
	private function getExistingVariants(string $productGid): array
	{
		$query = <<<'GQL'
		query($id: ID!) {
		  product(id: $id) {
		    variants(first: 100) {
		      edges {
		        node {
		          id
		          title
		          sku
		          selectedOptions { name value }
		        }
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

	/** @return array<int, string> */
	private function variantPayloadMatchKeys(array $payload): array
	{
		$keys = [];

		$optionValues = [];
		foreach ($payload['optionValues'] ?? [] as $i => $ov) {
			if (!is_array($ov) || empty($ov['name'])) {
				continue;
			}
			$optionValues[$i] = strtolower(trim((string) $ov['name']));
		}
		if ($optionValues !== []) {
			ksort($optionValues);
			$keys[] = 'opt:' . implode('|', $optionValues);
		}

		$skuPath = $this->fieldMapping->productVariantSkuWritePath();
		if ($skuPath !== null) {
			$sku = $this->fields->get($payload, $skuPath);
			if ($sku !== null && $sku !== '') {
				$keys[] = 'sku:' . strtolower(trim((string) $sku));
			}
		}

		if (!empty($payload['title'])) {
			$keys[] = 'title:' . strtolower(trim((string) $payload['title']));
		}

		return array_values(array_unique($keys));
	}

	/** @return array<int, string> */
	private function existingVariantMatchKeys(array $variant): array
	{
		$keys = [];

		$optionValues = [];
		foreach ($variant['selectedOptions'] ?? [] as $option) {
			$name = trim((string) ($option['name'] ?? ''));
			$value = trim((string) ($option['value'] ?? ''));
			if ($name === '' || $value === '' || strcasecmp($name, 'Title') === 0) {
				continue;
			}
			$optionValues[] = strtolower($value);
		}
		if ($optionValues !== []) {
			$keys[] = 'opt:' . implode('|', $optionValues);
		}

		if (!empty($variant['sku'])) {
			$keys[] = 'sku:' . strtolower(trim((string) $variant['sku']));
		}

		$title = trim((string) ($variant['title'] ?? ''));
		if ($title !== '' && strcasecmp($title, 'Default Title') !== 0) {
			$keys[] = 'title:' . strtolower($title);
		}

		return array_values(array_unique($keys));
	}

	/**
	 * @param array<string, array{id: string, title?: string, sku?: string, selectedOptions?: array}> $existingByKey
	 */
	private function findExistingVariant(array $existingByKey, array $payload): ?array
	{
		foreach ($this->variantPayloadMatchKeys($payload) as $key) {
			if (isset($existingByKey[$key])) {
				return $existingByKey[$key];
			}
		}

		return null;
	}

	private function replaceDefaultVariant(string $productGid, string $variantGid, array $payload): void
	{
		$this->bulkUpdateVariants($productGid, [['gid' => $variantGid, 'payload' => $payload]]);
	}

	/**
	 * @param array<int, array{gid: string, payload: array<string, mixed>}> $items
	 */
	private function bulkUpdateVariants(string $productGid, array $items): void
	{
		if ($items === []) {
			return;
		}

		$mutation = <<<'GQL'
		mutation($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
		  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
		    productVariants { id }
		    userErrors { field message }
		  }
		}
		GQL;

		$inputs         = [];
		$inventoryByGid = [];

        foreach ($items as $item) {
            $inventoryQty = $this->resolveVariantInventoryQuantities($item['payload']);
            $input        = $this->toGraphQLVariantInput($item['payload']);
            $input['id']  = $item['gid'];
            $this->resolveVariantOptionIds($productGid, $input);
            $this->extractInventoryQuantitiesFromVariantInput($input);
            $inputs[] = $input;

            if ($inventoryQty !== null) {
                $inventoryByGid[$item['gid']] = $inventoryQty;
            }
        }

		$this->recordWire('productVariantsBulkUpdate', $mutation, ['productId' => $productGid, 'variants' => $inputs]);

		$data = $this->graphql->query($mutation, [
			'productId' => $productGid,
			'variants'  => $inputs,
		]);
		$this->recordResponse($data['productVariantsBulkUpdate'] ?? $data);

		$errors = $this->graphql->extractUserErrors($data, 'productVariantsBulkUpdate');
		if (!empty($errors)) {
			throw new ShopifyApiException(
				'Shopify productVariantsBulkUpdate errors: ' . implode('; ', $errors),
				422,
				'productVariantsBulkUpdate'
			);
		}

		foreach ($inventoryByGid as $variantGid => $inventoryQty) {
			$this->applyVariantInventoryLevel($variantGid, $inventoryQty);
		}

		$this->mergeInventoryWireLog();
	}

	/** Append inventory GraphQL calls to the product wire log (inventorySetQuantities, inventoryActivate). */
	private function mergeInventoryWireLog(): void
	{
		$inventoryService = app(ShopifyInventoryService::class);
		if (!method_exists($inventoryService, 'takeWireLog')) {
			return;
		}

		foreach ($inventoryService->takeWireLog() as $entry) {
			$this->wireLog[] = $entry;
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $variants
	 */
	private function bulkCreateVariants(string $productGid, array $variants): void
	{
		if ($variants === []) {
			return;
		}

		$mutation = <<<'GQL'
		mutation bulkCreateVariants($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
			productVariantsBulkCreate(productId: $productId, variants: $variants) {
				productVariants { id }
				userErrors { field message }
			}
		}
		GQL;

		$variantsInput = array_map(function ($v) use ($productGid) {
			$input = $this->toGraphQLVariantInput($v);
			$this->resolveVariantOptionIds($productGid, $input);
			return $input;
		}, $variants);

		$this->recordWire('productVariantsBulkCreate', $mutation, ['productId' => $productGid, 'variants' => $variantsInput]);

		$data = $this->graphql->query($mutation, [
			'productId' => $productGid,
			'variants'  => $variantsInput,
		]);
		$this->recordResponse($data['productVariantsBulkCreate'] ?? $data);

		$errors = $this->graphql->extractUserErrors($data, 'productVariantsBulkCreate');
		if (!empty($errors)) {
			throw new ShopifyApiException(
				'Variant sync failed: ' . implode('; ', $errors),
				422,
				'productVariantsBulkCreate'
			);
		}
	}

	/**
	 * inventoryQuantities is only valid on variant CREATE — strip before bulk update.
	 *
	 * @return array<string, mixed>|null
	 */
	private function extractInventoryQuantitiesFromVariantInput(array &$input): ?array
	{
		if (!isset($input['inventoryQuantities'])) {
			return null;
		}

		$quantities = $input['inventoryQuantities'];
		unset($input['inventoryQuantities']);

		return is_array($quantities) ? $quantities : null;
	}

	/** Keep only variant bulk keys from field configs (+ GraphQL orchestration keys). */
	private function normalizeVariantBulkInput(array &$input): void
	{
		$allowed = array_flip(array_merge(
			$this->fieldMapping->variantBulkInputRoots(),
			self::VARIANT_BULK_STRUCTURAL,
		));

		foreach (array_keys($input) as $key) {
			if (!isset($allowed[$key])) {
				unset($input[$key]);
			}
		}

		foreach ($this->fieldMapping->variantBulkInputRoots() as $root) {
			if (!isset($input[$root]) || !is_array($input[$root])) {
				continue;
			}

			$this->pruneEmptyNestedArrays($input[$root]);

			if ($input[$root] === []) {
				unset($input[$root]);
			}
		}
	}

	/**
	 * Shopify GraphQL rejects empty arrays where an input object is expected
	 * (e.g. inventoryItem.measurement: [] after dropping incomplete weight).
	 *
	 * @param array<string, mixed> $data
	 */
	private function pruneEmptyNestedArrays(array &$data): void
	{
		foreach (array_keys($data) as $key) {
			if (!is_array($data[$key])) {
				continue;
			}

			$this->pruneEmptyNestedArrays($data[$key]);

			if ($data[$key] === []) {
				unset($data[$key]);
			}
		}
	}

	/**
	 * Drop incomplete measurement.weight using paths from field configs.
	 *
	 * @param array<string, mixed> $variant
	 */
	private function pruneEmptyMeasurement(array &$variant): void
	{
		$valuePath = $this->fieldMapping->variantConfigWritePathContaining('measurement.weight', 'value');
		if ($valuePath === null) {
			return;
		}

		$unitPath = $this->fieldMapping->variantConfigWritePathContaining('measurement.weight', 'unit');
		$value    = $this->fields->get($variant, $valuePath);
		$unit     = $unitPath ? $this->fields->get($variant, $unitPath) : null;

		$hasValue = $value !== null && $value !== '';
		$hasUnit  = $unit !== null && $unit !== '';
		$numeric  = $hasValue ? (float) $value : 0.0;

		if ($hasValue && !$hasUnit && $unitPath !== null && $numeric > 0) {
			$defaultUnit = $this->fieldMapping->defaultValueForVariantWritePath($unitPath);
			if ($defaultUnit !== null) {
				$this->fields->set($variant, $unitPath, $defaultUnit);
			}

			return;
		}

		if ($hasValue && $hasUnit && $numeric > 0) {
			return;
		}

		$measurementPath = preg_replace('/\.weight\.[^.]+$/', '', $valuePath) ?? '';
		if ($measurementPath !== '') {
			$this->fields->unsetPath($variant, $measurementPath);
		}
	}

	private function applyVariantInventoryLevel(string $variantGid, array $inventoryQuantities): void
	{
		$available = (int) ($inventoryQuantities['availableQuantity']
			?? $inventoryQuantities['quantity']
			?? 0);

		$channelMaps = app(\App\Services\ChannelMappingService::class);
		$odooLocationId = $channelMaps->defaultWarehouseOdooId();
		$locationNumeric = $channelMaps->defaultShopifyWarehouseLocationId($odooLocationId);

		if (!$locationNumeric) {
			Log::warning('ShopifyProductService: skipped inventory set — no warehouse mapping for Shopify location.');
			return;
		}

		$inventoryItemId = $this->getInventoryItemIdForVariant($variantGid);
		if (!$inventoryItemId) {
			Log::warning("ShopifyProductService: skipped inventory set — no inventoryItem for variant {$variantGid}.");
			return;
		}

		$mapping = app(FieldMappingService::class);
		$syntheticLocationKey = $odooLocationId ?? $locationNumeric;
		$payload = $mapping->buildErpToEcomInventoryPayload(
			$mapping->buildSyntheticInventoryQuant($available, $syntheticLocationKey)
		);

		if (!$this->payloadHasInventoryLocation($payload)) {
			$this->injectInventoryLocation($payload, $locationNumeric);
		}

		$wireContext = [];
		$wireKey     = $this->inventoryItemWireContextKey();
		if ($wireKey !== null) {
			$wireContext[$wireKey] = $this->fromGid($inventoryItemId);
		}

		app(ShopifyInventoryService::class)->setLevel($payload, $wireContext);
	}

	/**
	 * Qty from variant field configs — location always from warehouse channel mapping.
	 *
	 * @param  array<string, mixed>  $payload
	 * @return array<string, mixed>|null
	 */
	private function resolveVariantInventoryQuantities(array $payload): ?array
	{
		foreach ($this->variantInventoryQuantityPaths() as $path) {
			$qty = $this->fields->get($payload, $path);
			if ($qty !== null && $qty !== '') {
				return ['availableQuantity' => $qty];
			}
		}

		if (isset($payload['inventoryQuantities']) && is_array($payload['inventoryQuantities'])) {
			$block = $payload['inventoryQuantities'];
			$qty   = $block['availableQuantity'] ?? $block['quantity'] ?? null;
			if ($qty !== null && $qty !== '') {
				return ['availableQuantity' => $qty];
			}
		}

		foreach (['qty_available', 'available', 'quantity'] as $key) {
			if (array_key_exists($key, $payload) && $payload[$key] !== null && $payload[$key] !== '') {
				return ['availableQuantity' => $payload[$key]];
			}
		}

		foreach ($this->activeProductPayload['variants'] ?? [] as $variant) {
			if (!is_array($variant) || $variant === $payload) {
				continue;
			}

			foreach ($this->variantInventoryQuantityPaths() as $path) {
				$qty = $this->fields->get($variant, $path);
				if ($qty !== null && $qty !== '') {
					return ['availableQuantity' => $qty];
				}
			}
		}

		foreach (['qty_available', 'available', 'quantity'] as $key) {
			$value = $this->activeProductPayload[$key] ?? null;
			if ($value !== null && $value !== '') {
				return ['availableQuantity' => $value];
			}
		}

		return null;
	}

	/** @return list<string> */
	private function variantInventoryQuantityPaths(): array
	{
		$paths = [
			'inventoryQuantities.availableQuantity',
			'inventoryQuantities.quantity',
		];

		$settings = app(SettingsService::class);
		foreach (app(FieldMappingService::class)->getMappings(
			'product',
			$settings->ecomDriver(),
			$settings->erpDriver(),
			'variant',
			'erp_to_ecom'
		) as $config) {
			if (!$config->is_active) {
				continue;
			}

			$path = trim($config->ecom_field ?? $config->shopify_field ?? '');
			if ($path === '') {
				continue;
			}

			$lower = strtolower($path);
			if (!str_contains($lower, 'inventoryquantities') || str_contains($lower, 'locationid')) {
				continue;
			}

			$paths[] = $path;
		}

		return array_values(array_unique($paths));
	}

	private function inventoryItemWireContextKey(): ?string
	{
		foreach (app(FieldMappingService::class)->getInventoryErpToEcomConfigs() as $config) {
			$writePath = app(FieldMappingService::class)->resolveConfigWritePath($config);
			$key       = trim($config->ecom_field ?? '');
			if ($key !== '' && str_contains($writePath, 'inventoryItemId')) {
				return $key;
			}
		}

		return null;
	}

	/** @param array<string, mixed> $payload */
	private function payloadHasInventoryLocation(array $payload): bool
	{
		foreach (app(FieldMappingService::class)->getInventoryErpToEcomConfigs() as $config) {
			$path = trim($config->ecom_field ?? '');
			if ($path === '' || !str_contains($path, 'locationId')) {
				continue;
			}

			$value = $this->fields->get($payload, $path);
			if ($value !== null && $value !== '') {
				return true;
			}
		}

		return false;
	}

	/** @param array<string, mixed> $payload */
	private function injectInventoryLocation(array &$payload, string $locationNumeric): void
	{
		foreach (app(FieldMappingService::class)->getInventoryErpToEcomConfigs() as $config) {
			$path = trim($config->ecom_field ?? '');
			if ($path === '' || !str_contains($path, 'locationId')) {
				continue;
			}

			$current = $this->fields->get($payload, $path);
			if ($current === null || $current === '') {
				$this->fields->set($payload, $path, $locationNumeric);
			}

			return;
		}
	}

	private function getInventoryItemIdForVariant(string $variantGid): ?string
	{
		$query = <<<'GQL'
		query($id: ID!) {
		  productVariant(id: $id) {
		    inventoryItem { id }
		  }
		}
		GQL;

		try {
			$data = $this->graphql->query($query, ['id' => $variantGid]);
			return $data['productVariant']['inventoryItem']['id'] ?? null;
		} catch (\Throwable $e) {
			Log::warning('ShopifyProductService: could not read inventoryItem id: ' . $e->getMessage());
			return null;
		}
	}
	
	private function syncVariants(string $productGid, array $variants, array $options = []): void
	{
		if (empty($variants)) return;

		if (!empty($options)) {
			$this->ensureProductOptions($productGid, $options);
		}

		$existing = $this->getExistingVariants($productGid);

		if (count($existing) === 1 && strcasecmp($existing[0]['title'] ?? '', 'Default Title') === 0) {
			$this->replaceDefaultVariant($productGid, $existing[0]['id'], $variants[0]);
			if (count($variants) > 1) {
				$this->bulkCreateVariants($productGid, array_slice($variants, 1));
			}
			return;
		}

		$existingByKey = [];
		foreach ($existing as $ev) {
			foreach ($this->existingVariantMatchKeys($ev) as $key) {
				$existingByKey[$key] = $ev;
			}
		}

		$toUpdate = [];
		$toCreate = [];

		foreach ($variants as $payload) {
			$match = $this->findExistingVariant($existingByKey, $payload);
			if ($match !== null) {
				$toUpdate[] = ['gid' => $match['id'], 'payload' => $payload];
				foreach ($this->existingVariantMatchKeys($match) as $key) {
					unset($existingByKey[$key]);
				}
			} else {
				$toCreate[] = $payload;
			}
		}

		if ($toUpdate !== []) {
			$this->bulkUpdateVariants($productGid, $toUpdate);
		}
		if ($toCreate !== []) {
			$this->bulkCreateVariants($productGid, $toCreate);
		}
	}

	/**
	 * Create missing product options before setting variant optionValues.
	 * Shopify productUpdate does not accept productOptions — use productOptionsCreate.
	 *
	 * @param array<int, array{name: string, values: array<int, string>}> $options
	 */
	private function ensureProductOptions(string $productGid, array $options): void
	{
		if (empty($options)) {
			return;
		}

		$allExisting = $this->getProductOptionsWithIds($productGid);
		$existingBy  = [];
		foreach ($allExisting as $opt) {
			if (strcasecmp($opt['name'], 'Title') === 0) {
				continue;
			}
			$existingBy[strtolower($opt['name'])] = $opt;
		}

		$nextPosition = count($allExisting) + 1;
		$toCreate     = [];

		foreach ($options as $opt) {
			$name = trim((string) ($opt['name'] ?? ''));
			if ($name === '') {
				continue;
			}

			if (isset($existingBy[strtolower($name)])) {
				continue;
			}

			$values = array_values(array_filter(array_map(
				fn($v) => ['name' => (string) $v],
				(array) ($opt['values'] ?? [])
			), fn($v) => $v['name'] !== ''));

			if ($values === []) {
				continue;
			}

			$toCreate[] = [
				'name'     => $name,
				'position' => $nextPosition++,
				'values'   => $values,
			];
		}

		if ($toCreate === []) {
			return;
		}

		$mutation = <<<'GQL'
		mutation ensureProductOptions(
		  $productId: ID!
		  $options: [OptionCreateInput!]!
		  $variantStrategy: ProductOptionCreateVariantStrategy
		) {
		  productOptionsCreate(
		    productId: $productId
		    options: $options
		    variantStrategy: $variantStrategy
		  ) {
		    product { id }
		    userErrors { field message }
		  }
		}
		GQL;

		$variables = [
			'productId'       => $productGid,
			'options'         => $toCreate,
			'variantStrategy' => 'LEAVE_AS_IS',
		];

		$this->recordWire('productOptionsCreate', $mutation, $variables);

		$data = $this->graphql->query($mutation, $variables);
		$this->recordResponse($data['productOptionsCreate'] ?? $data);

		$errors = $this->graphql->extractUserErrors($data, 'productOptionsCreate');
		if (!empty($errors)) {
			throw new ShopifyApiException(
				'Shopify product options sync failed: ' . implode('; ', $errors),
				422,
				'productOptionsCreate'
			);
		}
	}

	/**
	 * @return array<int, array{id: string, name: string, position: int, values: array<int, string>}>
	 */
	private function getProductOptionsWithIds(string $productGid): array
	{
		$query = <<<'GQL'
		query($id: ID!) {
		  product(id: $id) {
		    options {
		      id
		      name
		      position
		      optionValues { id name }
		    }
		  }
		}
		GQL;

		try {
			$data = $this->graphql->query($query, ['id' => $productGid]);
		} catch (\Throwable $e) {
			Log::warning('ShopifyProductService: could not read product options: ' . $e->getMessage());
			return [];
		}

		$options = [];
		foreach ($data['product']['options'] ?? [] as $opt) {
			$name = (string) ($opt['name'] ?? '');
			if ($name === '') {
				continue;
			}
			$values = array_values(array_filter(array_map(
				fn($v) => (string) ($v['name'] ?? ''),
				$opt['optionValues'] ?? []
			)));
			$options[] = [
				'id'       => (string) ($opt['id'] ?? ''),
				'name'     => $name,
				'position' => (int) ($opt['position'] ?? 0),
				'values'   => $values,
			];
		}

		return $options;
	}

	/**
	 * Map variant option label leaf → Shopify optionId (paths/leaves from field config).
	 *
	 * @param array<string, mixed> $variantInput
	 */
	private function resolveVariantOptionIds(string $productGid, array &$variantInput): void
	{
		$specs = $this->fieldMapping->getVariantOptionSlotSpecs();
		if ($specs === []) {
			return;
		}

		$productOptions = $this->getProductOptionsWithIds($productGid);
		$byName         = [];
		foreach ($productOptions as $opt) {
			if (strcasecmp($opt['name'], 'Title') === 0) {
				continue;
			}
			$byName[strtolower($opt['name'])] = $opt;
		}

		$byPrefix = [];
		foreach ($specs as $spec) {
			$byPrefix[$spec['prefix']][] = $spec;
		}

		foreach ($byPrefix as $prefix => $prefixSpecs) {
			usort($prefixSpecs, fn ($a, $b) => $a['index'] <=> $b['index']);

			$resolved = [];
			foreach ($prefixSpecs as $spec) {
				$block = $this->fields->get($variantInput, "{$prefix}.{$spec['index']}");
				if (!is_array($block)) {
					continue;
				}

				$optionName = trim((string) ($block[$spec['labelLeaf']] ?? ''));
				$valueName  = trim((string) ($block[$spec['valueLeaf']] ?? ''));

				if ($optionName === '' && $valueName === '') {
					continue;
				}

				$match = $byName[strtolower($optionName)] ?? null;
				if (!$match) {
					throw new ShopifyApiException(
						"Shopify option \"{$optionName}\" does not exist on the product. "
						. 'Add two variant field-config rows per option index (lower sort_order = label leaf, next = value leaf).',
						422,
						'productVariantsBulkUpdate'
					);
				}

				$entry = ['name' => $valueName !== '' ? $valueName : ($match['values'][0] ?? '')];
				if ($match['id'] !== '') {
					$entry['optionId'] = $match['id'];
				} else {
					$entry['optionName'] = $match['name'];
				}

				$resolved[] = $entry;
			}

			if ($resolved !== []) {
				$variantInput[$prefix] = $resolved;
			} else {
				unset($variantInput[$prefix]);
			}
		}
	}

	/**
	 * @param array<int|string, mixed> $list
	 * @return array<int, mixed>
	 */
	private function normalizeIndexedList(array $list): array
	{
		$out = [];
		ksort($list, SORT_NATURAL);
		foreach ($list as $entry) {
			if (is_array($entry)) {
				$out[] = $entry;
			}
		}

		return $out;
	}

    public function get(string $shopifyProductId): ?array
    {
        try {
            $query = <<<'GQL'
            query getProduct($id: ID!) {
                product(id: $id) {
                    id
                    title
                    descriptionHtml
                    handle
                    status
                    vendor
                    productType
                    category {
                        id
                        name
                        fullName
                    }
                    tags
                    templateSuffix
                    publishedAt
                    onlineStoreUrl
                    createdAt
                    updatedAt
                    seo {
                        title
                        description
                    }
                    options {
                        id
                        name
                        position
                        values
                    }
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
                                position
                                selectedOptions {
                                    name
                                    value
                                }
                                inventoryItem {
                                    id
                                    tracked
                                    requiresShipping
                                    harmonizedSystemCode
                                    countryCodeOfOrigin
                                    measurement {
                                        weight {
                                            value
                                            unit
                                        }
                                    }
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
                                width
                                height
                            }
                        }
                    }
                    metafields(first: 20) {
                        edges {
                            node {
                                id
                                namespace
                                key
                                value
                                type
                            }
                        }
                    }
                    collections(first: 10) {
                        edges {
                            node {
                                id
                                title
                                handle
                            }
                        }
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

    public function buildPayload(array $erpTemplate, array $variants, array $attributeValues, array $related = []): array
    {
        return $this->fieldMapping->buildErpToEcomProductPayload(
            $erpTemplate,
            $variants,
            $attributeValues,
            related: $related
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // GraphQL variables — built from config paths (product.*, media.*)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build mutation variables from the config-driven payload.
     * Field configs write to product.vendor, media.0.originalSource, etc.
     *
     * @return array<string, mixed> e.g. ['product' => [...], 'media' => [...]]
     */
    private function buildGraphQLVariables(
        array $payload,
        bool $includeProductOptions = true,
        ?string $productGid = null
    ): array {
        $product = is_array($payload['product'] ?? null) ? $payload['product'] : [];
        $media   = is_array($payload['media'] ?? null) ? $payload['media'] : [];

        // Legacy flat keys at payload root (bare title/vendor without product. prefix in config)
        foreach ($payload as $key => $value) {
            if (!is_string($key) || str_starts_with($key, '_') || $value === null || $value === '') {
                continue;
            }
            if (in_array($key, self::STRUCTURAL, true) || in_array($key, self::MUTATION_ROOTS, true)) {
                continue;
            }

            if (str_contains($key, '.')) {
                [$root, $path] = explode('.', $key, 2);
                if ($root === 'product' && $path !== '') {
                    $this->fields->set($product, $path, $value);
                } elseif ($root === 'media' && $path !== '') {
                    $this->fields->set($media, $path, $value);
                }
                continue;
            }

            $product[$key] = $value;
        }

        if ($media === []) {
            $imageSource = $payload['images'] ?? $product['images'] ?? null;
            if ($imageSource !== null && $imageSource !== '') {
                if (is_string($imageSource)) {
                    $imageSource = [['attachment' => $imageSource]];
                }
                $media = $this->imagesToMediaInput((array) $imageSource);
                unset($product['images']);
            }
        }

        if ($includeProductOptions && !empty($payload['options'])) {
            $product['productOptions'] = array_map(fn ($o) => [
                'name'   => $o['name'],
                'values' => array_map(fn ($v) => ['name' => $v], (array) ($o['values'] ?? [])),
            ], $payload['options']);
        }

        if ($productGid !== null) {
            $product['id'] = $this->toGid('Product', $productGid);
        }

        $variables = ['product' => $this->prepareGraphQLInput($product)];

        $normalizedMedia = $this->normalizeMediaInput($media);
        if ($normalizedMedia !== []) {
            $variables['media'] = $normalizedMedia;
        }

        return $variables;
    }

    /** @param array<int, array<string, mixed>> $images */
    private function imagesToMediaInput(array $images): array
    {
        if (isset($images['attachment']) || isset($images['src']) || isset($images['originalSource'])) {
            $images = [$images];
        }

        $media = [];
        foreach ($images as $img) {
            if (!is_array($img)) {
                continue;
            }
            if (!empty($img['attachment'])) {
                $media[] = [
                    'mediaContentType' => 'IMAGE',
                    'originalSource'   => $this->resolveMediaOriginalSource((string) $img['attachment']),
                ];
            } elseif (!empty($img['src'])) {
                $media[] = [
                    'mediaContentType' => 'IMAGE',
                    'originalSource'   => $img['src'],
                ];
            } elseif (!empty($img['originalSource'])) {
                $media[] = [
                    'mediaContentType' => $img['mediaContentType'] ?? 'IMAGE',
                    'originalSource'   => $img['originalSource'],
                ];
            }
        }

        return $media;
    }

    /** @param array<int|string, mixed> $media */
    private function normalizeMediaInput(array $media): array
    {
        if ($media === []) {
            return [];
        }

        if (!array_is_list($media)) {
            ksort($media, SORT_NUMERIC);
        }

        $list = [];
        foreach ($media as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (!empty($item['attachment'])) {
                $item['originalSource'] = $this->resolveMediaOriginalSource((string) $item['attachment']);
                unset($item['attachment']);
            }

            if (empty($item['mediaContentType'])) {
                $item['mediaContentType'] = 'IMAGE';
            }

            if (!empty($item['originalSource'])) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * Prepare nested product input — prune empties only (no field-name routing).
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function prepareGraphQLInput(array $payload): array
    {
        $output = [];

        foreach ($payload as $key => $value) {
            if (in_array($key, self::STRUCTURAL, true)) {
                continue;
            }

            if (str_starts_with((string) $key, '_')) {
                continue;
            }

            if ($value === null || $value === '') {
                continue;
            }

            if (is_string($key) && str_contains($key, '.')) {
                $this->dotSet($output, $key, $value);
            } else {
                $output[$key] = $value;
            }
        }

        $this->pruneEmptyNestedArrays($output);

        foreach ($this->fieldMapping->getVariantOptionPrefixes() as $prefix) {
            if (!empty($output[$prefix]) && is_array($output[$prefix])) {
                $output[$prefix] = $this->normalizeIndexedList($output[$prefix]);
            }
        }

        $category = $this->normalizeTaxonomyCategoryInput($output['category'] ?? null);
        if ($category === null) {
            unset($output['category']);
        } else {
            $output['category'] = $category;
        }

        if (array_key_exists('status', $output)) {
            $status = $this->normalizeProductStatusInput($output['status']);
            if ($status === null) {
                unset($output['status']);
            } else {
                $output['status'] = $status;
            }
        }

        return $output;
    }

    /** ProductCreateInput.status must be ACTIVE, ARCHIVED, DRAFT, or UNLISTED — not a boolean. */
    private function normalizeProductStatusInput(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_bool($value)) {
            return $value ? 'ACTIVE' : 'DRAFT';
        }

        if (is_int($value) || (is_string($value) && ctype_digit(trim($value)))) {
            return ((int) $value) === 1 ? 'ACTIVE' : 'DRAFT';
        }

        $normalized = strtoupper(trim((string) $value));
        if (in_array($normalized, ['ACTIVE', 'ARCHIVED', 'DRAFT', 'UNLISTED'], true)) {
            return $normalized;
        }

        return match (strtolower(trim((string) $value))) {
            'active', 'published', 'true', 'yes', '1' => 'ACTIVE',
            'archived' => 'ARCHIVED',
            'unlisted' => 'UNLISTED',
            default    => 'DRAFT',
        };
    }

    /** ProductCreateInput.category must be a TaxonomyCategory GID, not a label or nested object. */
    private function normalizeTaxonomyCategoryInput(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_array($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, 'gid://shopify/TaxonomyCategory/')) {
            return $value;
        }

        if (ctype_digit($value)) {
            return "gid://shopify/TaxonomyCategory/{$value}";
        }

        return null;
    }

    /**
     * Resolve CreateMediaInput.originalSource from a public URL or Odoo base64 image.
     * Base64 is uploaded via Shopify staged uploads — no public app URL required.
     */
    private function resolveMediaOriginalSource(string $source): string
    {
        $source = trim($source);
        if ($source === '') {
            throw new ShopifyApiException('Product image source is empty', 422, 'media');
        }

        if (filter_var($source, FILTER_VALIDATE_URL)) {
            return $source;
        }

        $decoded = $this->decodeBase64Image($source);
        if ($decoded === null) {
            throw new ShopifyApiException(
                'Product image from ERP is not valid base64 image data',
                422,
                'media'
            );
        }

        return $this->uploadViaShopifyStagedUpload($decoded);
    }

    /** @return array{binary: string, mime: string, filename: string}|null */
    private function decodeBase64Image(string $input): ?array
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $mime = null;
        if (preg_match('#^data:(image/[^;]+);base64,#i', $input, $matches)) {
            $mime  = strtolower($matches[1]);
            $input = substr($input, (int) strpos($input, ',') + 1);
        }

        $input  = preg_replace('/\s+/', '', $input);
        $binary = base64_decode($input, true);
        if ($binary === false || $binary === '') {
            return null;
        }

        if (@getimagesizefromstring($binary) === false) {
            Log::warning('ShopifyProductService: decoded ERP image is not a valid image');

            return null;
        }

        if ($mime === null) {
            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime  = strtolower((string) ($finfo->buffer($binary) ?: 'image/jpeg'));
        }

        $extension = match ($mime) {
            'image/png'  => 'png',
            'image/gif'  => 'gif',
            'image/webp' => 'webp',
            default      => 'jpg',
        };

        return [
            'binary'   => $binary,
            'mime'     => $mime,
            'filename' => 'product.' . $extension,
        ];
    }

    /** @param array{binary: string, mime: string, filename: string} $image */
    private function uploadViaShopifyStagedUpload(array $image): string
    {
        $mutation = <<<'GQL'
        mutation stagedUploadsCreate($input: [StagedUploadInput!]!) {
            stagedUploadsCreate(input: $input) {
                stagedTargets {
                    url
                    resourceUrl
                    parameters { name value }
                }
                userErrors { field message }
            }
        }
        GQL;

        $data = $this->graphql->query($mutation, [
            'input' => [[
                'filename'   => $image['filename'],
                'mimeType'   => $image['mime'],
                'resource'   => 'PRODUCT_IMAGE',
                'httpMethod' => 'POST',
            ]],
        ]);

        $errors = $this->graphql->extractUserErrors($data, 'stagedUploadsCreate');
        if ($errors !== []) {
            throw new ShopifyApiException(
                'Shopify stagedUploadsCreate errors: ' . implode('; ', $errors),
                422,
                'stagedUploadsCreate'
            );
        }

        $target = $data['stagedUploadsCreate']['stagedTargets'][0] ?? null;
        if (!is_array($target) || empty($target['url']) || empty($target['resourceUrl'])) {
            throw new ShopifyApiException('Shopify stagedUploadsCreate returned no upload target', 422, 'stagedUploadsCreate');
        }

        $multipart = [];
        foreach ($target['parameters'] ?? [] as $param) {
            if (!is_array($param) || !isset($param['name'], $param['value'])) {
                continue;
            }
            $multipart[] = [
                'name'     => (string) $param['name'],
                'contents' => (string) $param['value'],
            ];
        }

        $multipart[] = [
            'name'     => 'file',
            'contents' => $image['binary'],
            'filename' => $image['filename'],
            'headers'  => ['Content-Type' => $image['mime']],
        ];

        try {
            (new Client(['timeout' => 60]))->post((string) $target['url'], ['multipart' => $multipart]);
        } catch (\Throwable $e) {
            throw new ShopifyApiException(
                'Shopify staged image upload failed: ' . $e->getMessage(),
                422,
                'stagedUpload',
                null,
                $e
            );
        }

        return (string) $target['resourceUrl'];
    }

    private function toGraphQLVariantInput(array $variantPayload): array
    {
        $variant = $this->prepareGraphQLInput($variantPayload);
        $this->finalizeVariantInput($variant);

        return $variant;
    }

    /** @param array<string, mixed> $variant */
    private function finalizeVariantInput(array &$variant): void
    {
        $this->normalizeVariantBulkInput($variant);
        $this->pruneEmptyMeasurement($variant);

        foreach ($this->fieldMapping->variantBulkInputRoots() as $root) {
            if (!isset($variant[$root]) || !is_array($variant[$root])) {
                continue;
            }

            $this->pruneEmptyNestedArrays($variant[$root]);

            if ($variant[$root] === []) {
                unset($variant[$root]);
            }
        }

        foreach ($this->fieldMapping->getVariantOptionPrefixes() as $prefix) {
            if (!empty($variant[$prefix]) && is_array($variant[$prefix])) {
                $variant[$prefix] = $this->normalizeIndexedList($variant[$prefix]);
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // GQL mutation strings
    // ─────────────────────────────────────────────────────────────────────

    private function productCreateMutation(): string
	{
		return <<<'GQL'
		mutation productCreate($product: ProductCreateInput!, $media: [CreateMediaInput!]) {
			productCreate(product: $product, media: $media) {
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

	private function productUpdateMutation(): string
	{
		return <<<'GQL'
		mutation productUpdate($product: ProductUpdateInput!, $media: [CreateMediaInput!]) {
			productUpdate(product: $product, media: $media) {
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
    // GraphQL structural decode — NOT field mapping.
    //
    // Converts Shopify GraphQL wire shape → storable JSON:
    //   • edges[].node  → flat array
    //   • gid://…       → numeric/string id
    //
    // Field names are preserved exactly as the API returns them (camelCase).
    // Which fields sync to ERP/ecom is 100% driven by product_field_configs —
    // same pattern as OdooProductService fetching only configured erp_fields.
    // ─────────────────────────────────────────────────────────────────────

    private function decodeGraphqlProduct(array $node): array
    {
        $decoded = $this->decodeGraphqlValue($node);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Recursively decode GraphQL response values. No field-name rewriting.
     */
    private function decodeGraphqlValue(mixed $value): mixed
    {
        if (is_string($value) && str_starts_with($value, 'gid://')) {
            return $this->fromGid($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        // Connection pattern: { edges: [ { node: … } ] } → [ … ]
        if (array_key_exists('edges', $value) && is_array($value['edges'])) {
            return array_map(
                fn ($edge) => $this->decodeGraphqlValue($edge['node'] ?? $edge),
                $value['edges']
            );
        }

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->decodeGraphqlValue($item);
        }

        return $out;
    }

    /** @deprecated Use decodeGraphqlProduct — kept as alias for internal callers */
    private function normalizeProduct(array $p): array
    {
        return $this->decodeGraphqlProduct($p);
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

    /**
     * Permanently delete a product from Shopify (GraphQL productDelete).
     */
    public function delete(string|int $id): void
    {
        $gid = $this->toGid('Product', (string) $id);

        $mutation = <<<'GQL'
        mutation productDelete($input: ProductDeleteInput!) {
            productDelete(input: $input) {
                deletedProductId
                userErrors { field message }
            }
        }
        GQL;

        $result = $this->graphql->query($mutation, [
            'input' => ['id' => $gid],
        ]);

        $errors = $result['productDelete']['userErrors'] ?? [];
        if ($errors !== []) {
            throw new ShopifyApiException(
                'Shopify productDelete failed: ' . ($errors[0]['message'] ?? 'unknown error')
            );
        }
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
    // Wire log (Info tab)
    // ─────────────────────────────────────────────────────────────────────

	private function recordWire(string $action, string $query, array $variables): void
	{
		$this->wireLog[] = ['action' => $action, 'query' => $query, 'variables' => $variables];
	}

	/**
	 * Attach the raw GraphQL response (incl. userErrors) to the most recent
	 * wire entry, so the Info tab shows what Shopify actually returned —
	 * not just the request we sent. Errors like "Media processing failed"
	 * live in userErrors and were previously discarded.
	 */
	private function recordResponse(mixed $response): void
	{
		if (!empty($this->wireLog)) {
			$this->wireLog[count($this->wireLog) - 1]['response'] = $response;
		}
	}

	public function takeWireLog(): array
	{
		$log = $this->wireLog;
		$this->wireLog = [];
		return $log;
	}
}