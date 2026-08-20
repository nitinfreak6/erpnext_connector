<?php

namespace App\Services\Ecom\Shopify;

use App\Exceptions\ShopifyApiException;
use App\Services\Ecom\EcomInterface;
use App\Services\Shopify\ShopifyService;
use App\Services\Shopify\ShopifyProductService;
use App\Services\Shopify\ShopifyOrderService;
use App\Services\Shopify\ShopifyInventoryService;
use App\Services\Shopify\ShopifyCustomerService;
use App\Services\Shopify\ShopifyFulfillmentService;
use App\Services\Shopify\ShopifyGraphQLService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Log;

class ShopifyEcomAdapter implements EcomInterface
{
	private ShopifyService $shopify;
	private ShopifyProductService $products;
	private ShopifyOrderService $orders;
	private ShopifyInventoryService $inventory;
	private ShopifyCustomerService $customers;
	private ShopifyFulfillmentService $fulfillment;
	private SettingsService $settings;

	private const FIELD_DISCOVERY_MAX_DEPTH = 10;

	public function __construct(
		ShopifyService $shopify,
		ShopifyProductService $products,
		ShopifyOrderService $orders,
		ShopifyInventoryService $inventory,
		ShopifyCustomerService $customers,
		ShopifyFulfillmentService $fulfillment,
		SettingsService $settings
	) {
		$this->shopify     = $shopify;
		$this->products    = $products;
		$this->orders      = $orders;
		$this->inventory   = $inventory;
		$this->customers   = $customers;
		$this->fulfillment = $fulfillment;
		$this->settings    = $settings;
	}

	public function driverName(): string
	{
		return 'shopify';
	}

	// ── Products ──────────────────────────────────────────────────────────

	public function getProducts(array $filters = []): array
	{
		return $this->products->list($filters);
	}

	public function getProduct(string|int $id): ?array
	{
		return $this->products->get($id);
	}

	public function createProduct(array $payload): array
	{
		return $this->products->create($payload);
	}

	public function updateProduct(string|int $id, array $payload): array
	{
		return $this->products->update($id, $payload);
	}

	public function deleteProduct(string|int $id): void
	{
		$this->products->delete($id);
	}

	public function deleteCustomer(string|int $id): void
	{
		app(\App\Services\Shopify\ShopifyCustomerService::class)->delete($id);
	}

	public function deleteOrder(string|int $id): void
	{
		$this->orders->cancel($id, 'Deleted from sync connector');
	}

	/**
	 * Sync ERP product → Shopify.
	 * Builds payload from field configs, creates or updates via GraphQL.
	 * This is the only method PushProductToEcomJob calls — all Shopify
	 * specifics stay inside this adapter.
	 */
	public function syncProduct(array $erpTemplate, array $variants, array $attributeValues, array $related = []): string
	{
		$erpId = (string) $erpTemplate['id'];

		$payload = $this->products->buildPayload($erpTemplate, $variants, $attributeValues, $related);

		if (empty($payload)) {
			throw new \RuntimeException(
				"ShopifyEcomAdapter: empty payload for ERP #{$erpId} — add field mappings in Product Field Config."
			);
		}

		$mapping = \App\Models\SyncMapping::where('entity_type', 'product')
			->where('erp_id', $erpId)
			->first();

		if ($mapping && $mapping->ecom_id) {
			$this->products->update($mapping->ecom_id, $payload);
			$shopifyId = $mapping->ecom_id;
			$mapping->fill([
				'ecom_driver'         => 'shopify',
				'erp_driver'          => app(\App\Services\SettingsService::class)->erpDriver(),
				'last_sync_direction' => 'erp_to_ecom',
				'last_synced_at'      => now(),
			])->save();
		} else {
			$result    = $this->products->create($payload);
			$shopifyId = (string) ($result['id'] ?? $result['product']['id'] ?? '');

			if ($shopifyId) {
				\App\Models\SyncMapping::updateOrCreate(
					['entity_type' => 'product', 'erp_id' => $erpId],
					[
						'ecom_id'             => $shopifyId,
						'ecom_driver'         => 'shopify',
						'erp_driver'          => app(\App\Services\SettingsService::class)->erpDriver(),
						'last_sync_direction' => 'erp_to_ecom',
						'last_synced_at'      => now(),
					]
				);
			}
		}

		return $shopifyId;
	}

	public function getVariants(array $productIds): array
	{
		$variants = [];
		foreach ($productIds as $productId) {
			$product = $this->getProduct($productId);
			if (isset($product['variants'])) {
				$variants = array_merge($variants, $product['variants']);
			}
		}
		return $variants;
	}

	// ── Orders ────────────────────────────────────────────────────────────

	public function getOrders(array $filters = []): array
	{
		return $this->orders->list($filters);
	}

	public function getOrder(string|int $id): array
	{
		$order = $this->orders->get((string) $id);
		if (empty($order) || !is_array($order)) {
			throw new \RuntimeException("Shopify order not found: {$id}");
		}

		return $order;
	}

	public function createOrder(array $orderData): array
	{
		return $this->orders->create($orderData);
	}

	public function updateOrder(string|int $id, array $updates): array
	{
		return $this->orders->update($id, $updates);
	}

	public function cancelOrder(string|int $id, ?string $reason = null): void
	{
		$this->orders->cancel($id, $reason);
	}

	// ── Inventory ─────────────────────────────────────────────────────────

	public function updateInventory(string|int $variantId, int $quantity, ?string $locationId = null, ?array $mappedPayload = null): void
	{
		$this->inventory->update($variantId, $quantity, $locationId, $mappedPayload);
	}

	public function getInventoryLevels(array $inventoryItemIds, string $locationId): array
	{
		return $this->inventory->getLevels($inventoryItemIds, $locationId);
	}

	// ── Customers ─────────────────────────────────────────────────────────

	public function getCustomers(array $filters = []): array
	{
		$result = $this->customers->list($filters);
		return $result['customers'] ?? [];
	}

	public function createCustomer(array $customerData): array
	{
		return $this->customers->create($customerData);
	}

	public function updateCustomer(string|int $id, array $customerData): array
	{
		return $this->customers->update($id, $customerData);
	}

	// ── Webhooks ──────────────────────────────────────────────────────────

	public function registerWebhooks(array $topics): array
	{
		$registered = [];
		$baseUrl    = config('app.url');

		$topicToEndpoint = [
			'orders/create'           => '/webhook/shopify/orders/create',
			'orders/updated'          => '/webhook/shopify/orders/updated',
			'products/create'         => '/webhook/shopify/products/create',
			'products/update'         => '/webhook/shopify/products/update',
			'inventory_levels/update' => '/webhook/shopify/inventory/update',
			'customers/create'        => '/webhook/shopify/customers/create',
			'customers/update'        => '/webhook/shopify/customers/update',
		];

		foreach ($topics as $topic) {
			if (!isset($topicToEndpoint[$topic])) {
				Log::warning("ShopifyEcomAdapter: unknown webhook topic: {$topic}");
				continue;
			}

			try {
				$webhook      = $this->shopify->createWebhook($topic, $baseUrl . $topicToEndpoint[$topic]);
				$registered[] = $webhook;
				Log::info("ShopifyEcomAdapter: registered webhook: {$topic}");
			} catch (\Throwable $e) {
				Log::error("ShopifyEcomAdapter: failed to register webhook: {$topic} — " . $e->getMessage());
			}
		}

		return $registered;
	}

	public function unregisterAllWebhooks(): void
	{
		foreach ($this->listWebhooks() as $webhook) {
			try {
				$this->shopify->deleteWebhook($webhook['id']);
			} catch (\Throwable $e) {
				Log::error("ShopifyEcomAdapter: failed to unregister webhook #{$webhook['id']} — " . $e->getMessage());
			}
		}
	}

	public function listWebhooks(): array
	{
		return $this->shopify->listWebhooks();
	}

	public function verifyWebhook(string $payload, string $signature): bool
	{
		return $this->shopify->verifyWebhook($payload, $signature);
	}

	// ── Fulfillment ───────────────────────────────────────────────────────

	public function createFulfillment(string|int $orderId, array $fulfillmentData): array
	{
		return $this->fulfillment->create($orderId, $fulfillmentData);
	}

	public function updateFulfillment(string|int $fulfillmentId, array $updates): void
	{
		$this->fulfillment->update($fulfillmentId, $updates);
	}

	public function getFulfillmentsForOrder(string|int $orderId): array
	{
		return $this->fulfillment->getForOrder((string) $orderId);
	}

	// ── Field discovery ───────────────────────────────────────────────────

	/**
	 * Field catalog for the mapping UI.
	 *
	 * ERP→Shopify product push: GraphQL mutation input introspection.
	 * Everything else: flatten live API sample records into dot paths.
	 */
	public function getAvailableFields(string $entityType): array
	{
		if ($entityType === 'product' && $this->settings->productSyncMode() === 'erp_to_ecom') {
			$template = $this->introspectGraphQLInputFields('ProductInput', 'template');
			$variant  = $this->introspectGraphQLInputFields('ProductVariantsBulkInput', 'variant');
			$media    = $this->introspectGraphQLInputFields('CreateMediaInput', 'template', 'media.0');

			return array_merge($template, $variant, $media);
		}

		if ($entityType === 'customer' && $this->settings->customerSyncMode() === 'erp_to_ecom') {
			return $this->introspectGraphQLInputFields('CustomerInput', 'default');
		}

		if ($entityType === 'dispatch') {
			return $this->dispatchAvailableFields();
		}

		$samples = $this->fetchSampleRecordsForFieldDiscovery($entityType);
		if ($samples === []) {
			return [];
		}

		return $this->flattenRecordsToFieldList($entityType, $samples);
	}

	/** @return array<int, array<string, mixed>> */
	private function fetchSampleRecordsForFieldDiscovery(string $entityType, int $limit = 5): array
	{
		return match ($entityType) {
			'product' => $this->getProducts(['limit' => $limit]),
			'sales_order' => $this->getOrders(['limit' => $limit]),
			'customer' => $this->getCustomers(['limit' => $limit]),
			'dispatch' => $this->fetchDispatchSamples($limit),
			'inventory' => $this->fetchInventorySamples($limit),
			default => [],
		};
	}

	/** @return array<int, array<string, mixed>> */
	private function fetchDispatchSamples(int $limit): array
	{
		foreach ($this->getOrders(['limit' => $limit]) as $order) {
			$orderId = $order['id'] ?? null;
			if (!$orderId) {
				continue;
			}

			$fulfillments = $this->fulfillment->getForOrder((string) $orderId);
			if ($fulfillments !== []) {
				return $fulfillments;
			}
		}

		return [[
			'lineItemsByFulfillmentOrder' => [[
				'fulfillmentOrderId'        => '',
				'fulfillmentOrderLineItems' => [['id' => '', 'quantity' => 0]],
			]],
			'notifyCustomer' => true,
			'trackingInfo'   => ['number' => '', 'company' => ''],
		]];
	}

	/** @return array<int, array<string, mixed>> */
	private function fetchInventorySamples(int $limit): array
	{
		foreach ($this->getProducts(['limit' => max(1, $limit)]) as $product) {
			foreach ($product['variants'] ?? [] as $variant) {
				if (!is_array($variant)) {
					continue;
				}

				$itemId = $variant['inventory_item_id'] ?? $variant['inventoryItemId'] ?? null;
				if ($itemId) {
					return [[
						'inventory_item_id' => $itemId,
						'sku'               => $variant['sku'] ?? '',
						'available'         => $variant['inventory_quantity'] ?? $variant['inventoryQuantity'] ?? 0,
						'location_id'       => '',
						'variant_id'        => $variant['id'] ?? null,
						'product_id'        => $product['id'] ?? null,
					]];
				}
			}
		}

		return [[
			'inventory_item_id' => '',
			'sku'               => '',
			'available'         => 0,
			'location_id'       => '',
		]];
	}

	/**
	 * @param  array<int, array<string, mixed>>  $records
	 * @return array<int, array{key:string,label:string,scope:string,sample?:string|null}>
	 */
	private function flattenRecordsToFieldList(string $entityType, array $records): array
	{
		if ($entityType === 'product') {
			$templateMap = [];
			$variantMap  = [];

			foreach ($records as $product) {
				if (!is_array($product) || $product === []) {
					continue;
				}

				$this->collectDiscoveryFields($product, '', 'template', $templateMap, ['variants']);

				foreach ($product['variants'] ?? [] as $variant) {
					if (!is_array($variant)) {
						continue;
					}
					$this->collectDiscoveryFields($variant, '', 'variant', $variantMap);
					break;
				}
			}

			return array_merge(
				$this->discoveryFieldList($templateMap),
				$this->discoveryFieldList($variantMap)
			);
		}

		$map = [];

		foreach ($records as $record) {
			if (!is_array($record) || $record === []) {
				continue;
			}

			$lineRoots = $this->detectDiscoveryLineRoots($record);
			$this->collectScopedDiscoveryRecord($record, '', $entityType, $lineRoots, $map);
		}

		return $this->discoveryFieldList($map);
	}

	/**
	 * @param  array<string, mixed>  $data
	 * @param  array<string, array<string, mixed>>  $map
	 * @param  list<string>  $excludeKeys
	 */
	private function collectDiscoveryFields(
		array $data,
		string $prefix,
		string $scope,
		array &$map,
		array $excludeKeys = [],
		int $depth = 0
	): void {
		if ($depth > self::FIELD_DISCOVERY_MAX_DEPTH) {
			return;
		}

		foreach ($data as $key => $value) {
			if ($prefix === '' && in_array($key, $excludeKeys, true)) {
				continue;
			}

			$path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

			if ($this->isDiscoveryScalar($value)) {
				$this->rememberDiscoveryField($map, $path, $scope, $value);
				continue;
			}

			if (!is_array($value)) {
				continue;
			}

			if ($value === []) {
				$this->rememberDiscoveryField($map, $path, $scope, '[]');
				continue;
			}

			if ($this->isDiscoveryListArray($value)) {
				if ($this->allDiscoveryScalars($value)) {
					$this->rememberDiscoveryField($map, $path, $scope, $value);
					foreach ($value as $i => $item) {
						$this->rememberDiscoveryField($map, "{$path}.{$i}", $scope, $item);
					}
					continue;
				}

				foreach ($value as $i => $item) {
					if (is_array($item)) {
						$this->collectDiscoveryFields($item, "{$path}.{$i}", $scope, $map, [], $depth + 1);
					} else {
						$this->rememberDiscoveryField($map, "{$path}.{$i}", $scope, $item);
					}
				}
				continue;
			}

			$this->collectDiscoveryFields($value, $path, $scope, $map, [], $depth + 1);
		}
	}

	/**
	 * @param  array<string, mixed>  $record
	 * @return list<string>
	 */
	private function detectDiscoveryLineRoots(array $record): array
	{
		$roots = [];

		foreach ($record as $key => $value) {
			if (!is_string($key) || !is_array($value) || !$this->isDiscoveryListArray($value)) {
				continue;
			}

			$first = $value[0] ?? null;
			if (is_array($first)) {
				$roots[] = $key;
			}
		}

		return $roots;
	}

	/** @param array<string, array<string, mixed>> $map */
	private function rememberDiscoveryField(
		array &$map,
		string $path,
		string $scope,
		mixed $sample,
		?string $label = null
	): void {
		if (isset($map[$path])) {
			return;
		}

		$map[$path] = [
			'key'    => $path,
			'label'  => $label ?? str_replace(['.', '_'], [' › ', ' '], $path),
			'scope'  => $scope,
			'sample' => $this->formatDiscoverySample($sample),
		];
	}

	/**
	 * @param  array<string, array<string, mixed>>  $map
	 * @return array<int, array<string, mixed>>
	 */
	private function discoveryFieldList(array $map): array
	{
		$fields = array_values($map);
		usort($fields, fn ($a, $b) => strcmp($a['key'], $b['key']));

		return $fields;
	}

	/** @param array<string, array<string, mixed>> $map */
	private function collectScopedDiscoveryRecord(
		array $data,
		string $prefix,
		string $entityType,
		array $lineRoots,
		array &$map,
		int $depth = 0
	): void {
		if ($depth > self::FIELD_DISCOVERY_MAX_DEPTH) {
			return;
		}

		foreach ($data as $key => $value) {
			if ($prefix === '' && in_array($key, $lineRoots, true) && is_array($value) && $value !== []) {
				foreach ($value as $i => $item) {
					if (!is_array($item)) {
						$this->rememberDiscoveryField($map, "{$key}.{$i}", 'line', $item);
						continue;
					}
					$this->collectDiscoveryFields($item, "{$key}.{$i}", 'line', $map);
					if ((int) $i === 0) {
						$this->collectDiscoveryFields($item, '', 'line', $map);
					}
				}
				continue;
			}

			$path  = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
			$scope = $this->discoveryScopeForPath($path, $entityType, $lineRoots);

			if ($this->isDiscoveryScalar($value)) {
				$this->rememberDiscoveryField($map, $path, $scope, $value);
				continue;
			}

			if (!is_array($value)) {
				continue;
			}

			if ($value === []) {
				$this->rememberDiscoveryField($map, $path, $scope, '[]');
				continue;
			}

			if ($this->isDiscoveryListArray($value)) {
				if ($this->allDiscoveryScalars($value)) {
					$this->rememberDiscoveryField($map, $path, $scope, $value);
					foreach ($value as $i => $item) {
						$this->rememberDiscoveryField($map, "{$path}.{$i}", $scope, $item);
					}
					continue;
				}

				foreach ($value as $i => $item) {
					if (is_array($item)) {
						$this->collectScopedDiscoveryRecord($item, "{$path}.{$i}", $entityType, $lineRoots, $map, $depth + 1);
					} else {
						$this->rememberDiscoveryField($map, "{$path}.{$i}", $scope, $item);
					}
				}
				continue;
			}

			$this->collectScopedDiscoveryRecord($value, $path, $entityType, $lineRoots, $map, $depth + 1);
		}
	}

	private function discoveryScopeForPath(string $path, string $entityType, array $lineRoots): string
	{
		if (in_array($entityType, ['customer', 'inventory', 'inventory_adjustment'], true)) {
			return 'default';
		}

		foreach ($lineRoots as $root) {
			if ($path === $root || str_starts_with($path, "{$root}.")) {
				return 'line';
			}
		}

		return 'header';
	}

	private function formatDiscoverySample(mixed $sample): ?string
	{
		if ($sample === null) {
			return null;
		}

		if (is_bool($sample)) {
			return $sample ? 'true' : 'false';
		}

		if (is_scalar($sample)) {
			$text = (string) $sample;

			return strlen($text) > 80 ? substr($text, 0, 77) . '...' : $text;
		}

		if (is_array($sample)) {
			$encoded = json_encode($sample, JSON_UNESCAPED_UNICODE);
			if ($encoded === false) {
				return null;
			}

			return strlen($encoded) > 80 ? substr($encoded, 0, 77) . '...' : $encoded;
		}

		return null;
	}

	private function isDiscoveryScalar(mixed $value): bool
	{
		return $value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value);
	}

	/** @param array<mixed> $value */
	private function isDiscoveryListArray(array $value): bool
	{
		if ($value === []) {
			return true;
		}

		return array_keys($value) === range(0, count($value) - 1);
	}

	/** @param array<mixed> $value */
	private function allDiscoveryScalars(array $value): bool
	{
		foreach ($value as $item) {
			if (!$this->isDiscoveryScalar($item)) {
				return false;
			}
		}

		return true;
	}

	/** @return array<int, array{key:string,label:string,scope:string,directions?:list<string>}> */
	private function dispatchAvailableFields(): array
	{
		// ERP → Ecom: FulfillmentInput (fulfillmentCreate mutation)
		$pushHeader = $this->introspectGraphQLInputFields(
			'FulfillmentInput',
			'header',
			'',
			0,
			collapseListIndex: true
		);
		$pushLine = $this->introspectGraphQLInputFields(
			'FulfillmentOrderLineItemInput',
			'line',
			'lineItemsByFulfillmentOrder.fulfillmentOrderLineItems',
			0,
			collapseListIndex: false
		);

		// Ecom → ERP: Fulfillment object (+ line items for reverse line scope)
		$pullHeader = $this->introspectGraphQLObjectFields('Fulfillment', 'header');
		$pullLine   = $this->introspectGraphQLObjectFields('FulfillmentLineItem', 'line', 'fulfillmentLineItems');

		$tag = static fn (array $fields, string $direction) => array_map(
			static fn (array $f) => array_merge($f, ['directions' => [$direction]]),
			$fields
		);

		return array_merge(
			$tag($pushHeader, 'erp_to_ecom'),
			$tag($pushLine, 'erp_to_ecom'),
			$tag([
				[
					'key'   => 'message',
					'label' => 'message (fulfillmentCreate mutation argument — not FulfillmentInput)',
					'scope' => 'header',
				],
			], 'erp_to_ecom'),
			$tag($pullHeader, 'ecom_to_erp'),
			$tag($pullLine, 'ecom_to_erp'),
		);
	}

	/** @return array<int, array{key:string,label:string,scope:string}> */
	private function introspectGraphQLObjectFields(
		string $typeName,
		string $scope,
		string $pathPrefix = '',
		int $depth = 0
	): array {
		if ($depth > 4) {
			return [];
		}

		try {
			$query = <<<'GQL'
			query IntrospectObject($name: String!) {
				__type(name: $name) {
					fields {
						name
						type {
							kind
							name
							ofType { kind name ofType { kind name ofType { kind name } } }
						}
					}
				}
			}
			GQL;

			$data   = app(ShopifyGraphQLService::class)->query($query, ['name' => $typeName]);
			$fields = $data['__type']['fields'] ?? [];
		} catch (\Throwable $e) {
			Log::warning("Shopify introspect object {$typeName} failed: " . $e->getMessage());

			return [];
		}

		$skip = ['events', 'fulfillmentOrders', 'order', 'service', 'location', 'legacyResourceId'];
		$out  = [];

		foreach ($fields as $field) {
			$name = (string) ($field['name'] ?? '');
			if ($name === '' || in_array($name, $skip, true)) {
				continue;
			}

			$rawType = $field['type'] ?? [];
			$type    = $this->unwrapGraphQLType($rawType);
			$kind    = $type['kind'] ?? '';

			if (in_array($kind, ['OBJECT', 'INTERFACE'], true) && !empty($type['name'])) {
				if (in_array($type['name'], ['FulfillmentOrderConnection', 'FulfillmentEventConnection', 'FulfillmentLineItemConnection'], true)) {
					continue;
				}

				$path = $pathPrefix === '' ? $name : "{$pathPrefix}.{$name}";
				$out  = array_merge(
					$out,
					$this->introspectGraphQLObjectFields($type['name'], $scope, $path, $depth + 1)
				);
				continue;
			}

			$path = $pathPrefix === '' ? $name : "{$pathPrefix}.{$name}";
			$out[] = [
				'key'   => $path,
				'label' => str_replace('.', ' › ', $path),
				'scope' => $scope,
			];
		}

		return $out;
	}

	/** @return array<int, array{key:string,label:string,scope:string}> */
	private function introspectGraphQLInputFields(
		string $typeName,
		string $scope,
		string $pathPrefix = '',
		int $depth = 0,
		bool $collapseListIndex = false
	): array {
		if ($depth > 5) {
			return [];
		}

		try {
			$query = <<<'GQL'
			query IntrospectInput($name: String!) {
				__type(name: $name) {
					inputFields {
						name
						type {
							kind
							name
							ofType { kind name ofType { kind name ofType { kind name } } }
						}
					}
				}
			}
			GQL;

			$data   = app(ShopifyGraphQLService::class)->query($query, ['name' => $typeName]);
			$fields = $data['__type']['inputFields'] ?? [];
		} catch (\Throwable $e) {
			Log::warning("Shopify introspect {$typeName} failed: " . $e->getMessage());

			return [];
		}

		$out = [];

		foreach ($fields as $field) {
			$name = (string) ($field['name'] ?? '');
			if ($name === '') {
				continue;
			}

			$rawType = $field['type'] ?? [];
			$isList  = ($rawType['kind'] ?? '') === 'LIST' || (($rawType['ofType']['kind'] ?? '') === 'LIST');
			$type    = $this->unwrapGraphQLType($rawType);
			$kind    = $type['kind'] ?? '';

			$path = $pathPrefix === '' ? $name : "{$pathPrefix}.{$name}";
			if ($isList && $kind === 'INPUT_OBJECT') {
				$path .= '.0';
			}

			if ($kind === 'INPUT_OBJECT' && !empty($type['name'])) {
				$out = array_merge(
					$out,
					$this->introspectGraphQLInputFields(
						$type['name'],
						$scope,
						$path,
						$depth + 1,
						$collapseListIndex
					)
				);
				continue;
			}

			if (in_array($kind, ['LIST', 'NON_NULL', 'SCALAR', 'ENUM'], true) || $kind === '') {
				$finalPath = $collapseListIndex
					? preg_replace('/\.\d+\./', '.', $path) ?? $path
					: $path;

				$out[] = [
					'key'   => $finalPath,
					'label' => str_replace('.', ' › ', $finalPath),
					'scope' => $scope,
				];
			}
		}

		return $out;
	}

	/** @param array<string, mixed> $type */
	private function unwrapGraphQLType(array $type): array
	{
		while (in_array($type['kind'] ?? '', ['NON_NULL', 'LIST'], true) && isset($type['ofType'])) {
			$type = $type['ofType'];
		}

		return $type;
	}

	public function getMappingOptions(string $type, ?string $search = null): array
	{
		return match ($type) {
			'category'  => $this->taxonomyOptions($search),
			'warehouse' => $this->locationOptions(),
			
			default     => [],
		};
	}

	private function locationOptions(): array
	{
		$q = 'query { locations(first: 100, includeInactive: false) {
				edges { node { id name } } } }';
		$data  = app(\App\Services\Shopify\ShopifyGraphQLService::class)->query($q);
		$edges = $data['locations']['edges'] ?? [];
		return array_map(fn($e) => [
			'id'    => $e['node']['id'],     // gid://shopify/Location/123…  → external_id
			'label' => $e['node']['name'],   // → external_label
		], $edges);
	}

	private function taxonomyOptions(?string $search): array
	{
		$q = 'query($s:String){ taxonomy { categories(first: 50, search: $s) {
				edges { node { id fullName } } } } }';
		$data  = app(\App\Services\Shopify\ShopifyGraphQLService::class)
					->query($q, $search ? ['s' => $search] : []);
		$edges = $data['taxonomy']['categories']['edges'] ?? [];
		return array_map(fn($e) => [
			'id'    => $e['node']['id'],        // gid → external_id
			'label' => $e['node']['fullName'],  // → external_label
		], $edges);
	}
	
	public function takeWireLog(): array
	{
		$wire = method_exists($this->products, 'takeWireLog') ? $this->products->takeWireLog() : [];
		$inv  = method_exists($this->inventory, 'takeWireLog') ? $this->inventory->takeWireLog() : [];
		$cust = method_exists($this->customers, 'takeWireLog') ? $this->customers->takeWireLog() : [];

		return array_merge($wire, $inv, $cust);
	}
}