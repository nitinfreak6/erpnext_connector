<?php

namespace App\Services\Sync;

use App\Models\EntityDefinition;
use App\Models\ProductCache;
use App\Models\ProductFieldConfig;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\FieldMappingService;
use App\Services\ChannelMappingService;
use App\Services\Config\NestedFieldResolver;
use App\Services\Config\ValueConditionMapper;
use App\Services\SettingsService;
use App\Services\ProductCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * UniversalSyncService
 *
 * Syncs ANY entity (product, sales_order, customer, inventory, dispatch...)
 * between ANY ERP and ANY ecom platform.
 *
 * All field mappings come from product_field_configs table — nothing hardcoded.
 * Adding a new entity : add rows in product_field_configs for that entity_type.
 * Adding a new driver : implement ErpInterface or EcomInterface.
 *
 * ── HOW LINE ITEMS WORK (no extra columns needed) ─────────────────────────
 *
 * Admin enters a dot-notation ecom_field that starts with the line-items array
 * key, e.g.:
 *
 *   ecom_field = "line_items.price_set.presentment_money.amount"
 *   erp_field  = "price_unit"
 *   scope      = "header"
 *
 * The service detects that "line_items" is an array in the ecom payload,
 * classifies this config as item-level, then for each item resolves
 * "price_set.presentment_money.amount" within that item object.
 *
 * To tell the service which ERP field holds the line array (e.g. "order_line"
 * in Odoo), create ONE header-scope row with transform = "line_container":
 *
 *   ecom_field = "line_items"    (the ecom array key)
 *   erp_field  = "order_line"    (the ERP ORM field — any name for any ERP)
 *   transform  = "line_container"
 *   scope      = "header"
 *
 * If no line_container row exists, line-scope configs are used to infer ERP/ecom line keys.
 *
 * Field configs drive payload mapping per entity type and direction.
 */
class UniversalSyncService
{
	public function __construct(
		private readonly EcomInterface   $ecom,
		private readonly ErpInterface    $erp,
		private readonly SettingsService $settings,
		private readonly NestedFieldResolver $fields,
	) {}

	/**
	 * Refresh stale order payloads with synced customer data before ERP push.
	 *
	 * @param  array<string, mixed>  $ecomOrder
	 * @return array<string, mixed>
	 */
	public function mergeSyncedCustomerOntoOrder(array $ecomOrder): array
	{
		$mapping = $this->findSyncedCustomerMappingForOrder($ecomOrder);
		if ($mapping === null) {
			return $ecomOrder;
		}

		$customer = $this->orderCustomerFromPayload($ecomOrder);
		$payload  = $mapping->payload();
		if (!is_array($payload)) {
			$payload = [];
		}

		if (($customer['id'] ?? '') === '' && !empty($mapping->ecom_id)) {
			$customer['id'] = (string) $mapping->ecom_id;
		}

		foreach (['email', 'firstName', 'first_name', 'lastName', 'last_name', 'phone'] as $key) {
			if (($customer[$key] ?? '') === '' && !empty($payload[$key])) {
				$customer[$key] = $payload[$key];
			}
		}

		if ($this->resolveOrderEmailFromPayload($ecomOrder) === '' && !empty($payload['email'])) {
			$ecomOrder['email'] = $payload['email'];
		}

		if ($customer !== []) {
			$ecomOrder['customer'] = $customer;
		}

		return $ecomOrder;
	}

	// ── ERP → Ecom ────────────────────────────────────────────────────────

	public function syncFromErpToEcom(string $entityType, array $erpData, ?string $scope = null): array
	{
		$entity = EntityDefinition::where('entity_type', $entityType)->firstOrFail();

		if (!$entity->is_active) {
			throw new \RuntimeException("Entity [{$entityType}] is not active.");
		}

		if ($entityType === 'product') {
			$template = $erpData['template'] ?? $erpData;
			$related  = array_filter([
				'vendors' => $erpData['vendors'] ?? null,
			], fn ($v) => $v !== null && $v !== []);

			$ecomPayload = app(FieldMappingService::class)->buildErpToEcomProductPayload(
				$template,
				$erpData['variants'] ?? [],
				$erpData['attribute_values'] ?? [],
				related: $related
			);

			$ecomPayload = $this->enrichProductVariantInventoryQuantities($ecomPayload, $template);
		} elseif ($entityType === 'customer') {
			$ecomPayload = app(FieldMappingService::class)->buildErpToEcomCustomerPayload($erpData);
			$erpId     = (string) ($erpData['id'] ?? '');

			if (empty($ecomPayload)) {
				$message = 'No erp→ecom customer field mappings produced a payload. '
					. 'Add active customer field configs with direction erp_to_ecom in Field Config.';

				if ($erpId !== '') {
					$this->markErpToEcomFailed($entityType, $erpId, $message);
				}

				throw new \RuntimeException($message);
			}
		} elseif (in_array($entityType, ['dispatch', 'sales_order'], true)) {
			$ecomPayload = $this->buildEcomPayloadFull($entityType, $erpData);
			$erpIdEarly  = (string) ($erpData['id'] ?? '');

			if (empty($ecomPayload)) {
				$message = "No erp→ecom field mappings produced a payload for {$entityType}. "
					. 'Add active dispatch/sales order field configs with direction erp_to_ecom in Field Config.';

				if ($erpIdEarly !== '') {
					$this->markErpToEcomFailed($entityType, $erpIdEarly, $message);
				}

				throw new \RuntimeException($message);
			}
		} else {
			$fieldConfigs = $this->getFieldConfigs($entityType, $scope, 'erp_to_ecom');

			if ($fieldConfigs->isEmpty()) {
				throw new \RuntimeException(
					"No erp→ecom field configs for {$entityType}. "
					. 'Add active mappings with direction erp_to_ecom in Field Config.'
				);
			}

			$ecomPayload = $this->buildEcomPayload($erpData, $fieldConfigs);
		}

		$erpId = (string) ($erpData['id'] ?? '');

		// Carry through any injected meta-keys (prefixed _) that adapters need
		// but that don't correspond to field configs (e.g. _ecom_order_id for dispatch).
		foreach ($erpData as $key => $val) {
			if (str_starts_with($key, '_')) {
				$ecomPayload[$key] = $val;
			}
		}

		$mapping = SyncMapping::where('entity_type', $entityType)
			->where('erp_id', $erpId)
			->where('erp_driver', $this->erp->driverName())
			->first();

		$log = SyncLog::create([
			'direction'       => SyncLog::DIRECTION_ERP_TO_ECOM,
			'entity_type'     => $entityType,
			'entity_id'       => $erpId,
			'action'          => ($mapping && $mapping->ecom_id) ? 'update' : 'create',
			'status'          => SyncLog::STATUS_PROCESSING,
			'request_payload' => json_encode(
				in_array($entityType, ['sales_order', 'customer'], true)
					? ['mapped_payload' => $ecomPayload]
					: $ecomPayload,
				JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
		]);

		try {
			if ($mapping && $mapping->ecom_id) {
				$result = $this->updateInEcom($entityType, $mapping->ecom_id, $ecomPayload);
				$ecomId = $mapping->ecom_id;
				Log::info("UniversalSyncService: updated {$entityType} #{$erpId} → {$this->ecom->driverName()} #{$ecomId}");
			} else {
				$result = $this->createInEcom($entityType, $ecomPayload);
				$ecomId = (string) ($result['id'] ?? '');

				if ($ecomId === '' && $entityType === 'customer') {
					throw new \RuntimeException(
						$this->ecom->driverName() . ' customer create/update returned no customer ID.'
					);
				}

				if ($ecomId && $erpId) {
					// Guard against UniqueConstraintViolation:
					// A row may already exist matched by ecom_id (e.g. customer existed in Shopify
					// before the mapping was stored). Check both erp_id and ecom_id before inserting.
					$existing = SyncMapping::where('entity_type', $entityType)
						->where(function ($q) use ($erpId, $ecomId, $entityType) {
							$q->where('erp_id', $erpId)
							->orWhere('ecom_id', $ecomId);
						})
						->first();

					if ($existing) {
						$existing->update([
							'erp_id'              => $erpId,
							'ecom_id'             => $ecomId,
							'erp_driver'          => $this->erp->driverName(),
							'ecom_driver'         => $this->ecom->driverName(),
							'last_synced_at'      => now(),
							'last_sync_direction' => 'erp_to_ecom',
						]);
					} else {
						SyncMapping::create([
							'entity_type'         => $entityType,
							'erp_id'              => $erpId,
							'ecom_id'             => $ecomId,
							'erp_driver'          => $this->erp->driverName(),
							'ecom_driver'         => $this->ecom->driverName(),
							'last_synced_at'      => now(),
							'last_sync_direction' => 'erp_to_ecom',
						]);
					}
				}

				Log::info("UniversalSyncService: created {$entityType} #{$erpId} → {$this->ecom->driverName()} #{$ecomId}");
			}

			$log->markSuccess(json_encode(array_merge(['ecom_id' => $ecomId], is_array($result) ? $result : [])));

			if ($entityType === 'sales_order') {
				$this->persistSalesOrderLogPayload($log, $ecomPayload, $result);
			} elseif ($entityType === 'customer') {
				$this->persistErpToEcomWireLog($log, $ecomPayload);
			}

			$this->markErpToEcomSynced(
				$entityType,
				$erpId,
				$ecomId,
				$erpData['write_date'] ?? null
			);
		} catch (\Throwable $e) {
			if (in_array($entityType, ['customer', 'sales_order'], true) && isset($log, $ecomPayload)) {
				$this->persistErpToEcomWireLog($log, $ecomPayload);
			}

			$message = SyncErrorFormatter::short($e) ?? 'Sync failed.';
			$log->markFailed($message, ['full' => $e->getMessage()]);
			$this->markErpToEcomFailed($entityType, $erpId, $message);
			throw $e;
		}

		return array_merge($result, ['id' => $ecomId, 'ecom_id' => $ecomId]);
	}

	// ── Ecom → ERP ────────────────────────────────────────────────────────

	public function syncFromEcomToErp(string $entityType, array $ecomData, ?string $scope = null): array
	{
		$entity = EntityDefinition::where('entity_type', $entityType)->firstOrFail();

		if (!$entity->is_active) {
			throw new \RuntimeException("Entity [{$entityType}] is not active.");
		}

		// Products use template/variant scoped configs and reverse_transform —
		// build them through the same config-driven mapper the manual push uses,
		// so create AND update stay consistent. buildErpPayloadFull is for
		// header/line entities (orders, customers).
		if ($entityType === 'product') {
			$erpPayload = app(\App\Services\FieldMappingService::class)->buildErpProductPayload(
				$ecomData,
				$this->ecom->driverName(),
				$this->erp->driverName()
			);

			if (empty($erpPayload)) {
				throw new \RuntimeException(
					'No ecom→erp product field mappings produced a payload. Add active mappings with direction '
					. '"ecom_to_erp" in Product Field Config.'
				);
			}
		} elseif ($entityType === 'customer') {
			$erpPayload = app(\App\Services\FieldMappingService::class)->buildErpCustomerPayload(
				$ecomData,
				$this->ecom->driverName(),
				$this->erp->driverName()
			);

			if (empty($erpPayload)) {
				throw new \RuntimeException(
					'No ecom→erp customer field mappings produced a payload. '
					. 'Add active customer field configs (scope=default, direction ecom_to_erp or unset) in Field Config.'
				);
			}
		} elseif ($entityType === 'inventory') {
			$erpPayload = $this->buildErpPayloadForEntity($entityType, $ecomData, $scope ?? 'default');
		} else {
			$erpPayload = $this->buildErpPayloadFull($entityType, $ecomData, $scope ?? 'header');
		}

		if ($entityType === 'sales_order' && !$this->salesOrderHasCustomerReference($erpPayload)) {
			$emailHint = $this->resolveOrderEmailFromPayload($ecomData);
			$nameHint  = $this->orderCustomerNameCandidates($ecomData);
			$customerField = $this->salesOrderCustomerErpField();
			throw new \RuntimeException(
				"Order push aborted: {$customerField} (Customer) could not be resolved. "
				. ($emailHint === ''
					? 'Re-fetch the order from Shopify — the cached payload has no customer email'
						. ($nameHint === [] ? ' or billing/shipping name.' : '.')
					: "No ERP customer found for {$emailHint}. Post the customer to {$this->settings->erpDisplayName()} first. ")
				. ' Enable Customer sync, fetch/post customers, then retry the order.'
			);
		}

		$ecomId     = (string) ($ecomData['id'] ?? '');

		$mapping = SyncMapping::where('entity_type', $entityType)
			->where('ecom_id', $ecomId)
			->where('ecom_driver', $this->ecom->driverName())
			->first();

		$log = SyncLog::create([
			'direction'   => SyncLog::DIRECTION_ECOM_TO_ERP,
			'entity_type' => $entityType,
			'entity_id'   => $ecomId,
			'action'      => ($mapping && $mapping->erp_id) ? 'update' : 'create',
			'status'      => SyncLog::STATUS_PROCESSING,
			'request_payload' => json_encode(
				['mapped_payload' => $erpPayload, 'driver' => $this->erp->driverName()],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
		]);

		try {
			if ($mapping && $mapping->erp_id) {
				$result = $this->updateInErp($entityType, $mapping->erp_id, $erpPayload, $ecomData);
				$erpId  = $mapping->erp_id;
				Log::info("UniversalSyncService: updated {$entityType} ecom#{$ecomId} → {$this->erp->driverName()} #{$erpId}");
			} else {
				$result = $this->createInErp($entityType, $erpPayload, $ecomData);
				$erpId  = (string) ($result['id'] ?? '');

				// Always store mapping — even when erpId is 0 (product pulled but ERP create not implemented yet)
				// This ensures products appear in the ecom_to_erp table immediately after pull
				if ($ecomId) {
					SyncMapping::updateOrCreate(
						['entity_type' => $entityType, 'ecom_id' => (string) $ecomId, 'ecom_driver' => $this->ecom->driverName()],
						[
							'erp_id'              => ($erpId && $erpId !== '0') ? $erpId : null,
							'erp_driver'          => $this->erp->driverName(),
							'last_synced_at'      => now(),
							'last_sync_direction' => 'ecom_to_erp',
							'ecom_handle'         => $ecomData['handle'] ?? $ecomData['name'] ?? null,
						]
					);
				}

				Log::info("UniversalSyncService: created {$entityType} ecom#{$ecomId} → {$this->erp->driverName()} #{$erpId}");
			}

			$this->finalizeEcomToErpLog($log, $entityType, $erpId, $erpPayload);

			$this->markEcomToErpSynced($entityType, $ecomId, $ecomData);
		} catch (\Throwable $e) {
			$message = SyncErrorFormatter::short($e) ?? 'Sync failed.';
			$this->markEcomToErpFailed($entityType, $ecomId, $message);

			$wire = method_exists($this->erp, 'takeWireLog') ? $this->erp->takeWireLog() : [];

			if ($wire) {
				$this->attachEcomToErpWireLog($log, $wire, $entityType, null, $message, $e->getMessage(), $erpPayload ?? null);
			} else {
				$log->update([
					'status'           => SyncLog::STATUS_FAILED,
					'error_message'    => $message,
					'error_context'    => ['full' => $e->getMessage()],
					'request_payload'  => json_encode(
						[
							'driver'         => $this->erp->driverName(),
							'mapped_payload' => $erpPayload ?? [],
						],
						JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
					),
					'response_payload' => json_encode(['error' => $message], JSON_UNESCAPED_UNICODE),
					'attempts'         => ($log->attempts ?? 0) + 1,
					'synced_at'        => now(),
				]);
			}
			throw $e;
		}

		return array_merge($result, ['id' => $erpId, 'erp_id' => $erpId]);
	}

	// ── Public payload builder ────────────────────────────────────────────

	public function buildErpPayloadOnly(string $entityType, array $ecomData, string $scope = 'header'): array
	{
		return $this->buildErpPayloadForEntity($entityType, $ecomData, $scope);
	}

	/** Build ERP→Ecom payload from field configs (no API call). */
	public function buildEcomPayloadForEntity(string $entityType, array $erpData, ?string $scope = null, array $pushContext = []): array
	{
		$scope = $scope ?? ($entityType === 'inventory' ? 'default' : 'header');
		$fieldConfigs = $this->getFieldConfigs($entityType, $scope, 'erp_to_ecom');

		if ($fieldConfigs->isEmpty()) {
			Log::warning("UniversalSyncService: No erp→ecom field configs for {$entityType}, scope={$scope}");
		}

		if ($entityType === 'inventory') {
			$erpData = $this->enrichInventoryQuantData($erpData);

			return app(FieldMappingService::class)->buildErpToEcomInventoryPayload(
				$erpData,
				$this->ecom->driverName(),
				$this->erp->driverName(),
				$pushContext
			);
		}

		if (in_array($entityType, ['dispatch', 'sales_order'], true)) {
			if ($pushContext !== []) {
				$erpData['_push'] = array_merge($erpData['_push'] ?? [], $pushContext);
			}

			$lineConfigs   = $this->getFieldConfigs($entityType, 'line', 'erp_to_ecom');
			$headerConfigs = $this->getFieldConfigs($entityType, 'header', 'erp_to_ecom');
			$lineContainer = $this->requireLineContainer($entityType);

			if ($lineContainer !== null) {
				$erpData = $this->enrichEntityLines($entityType, $erpData);
			}

			$payload = $this->buildEcomPayloadFull($entityType, $erpData);

			$payload = $this->assembleNestedEcomPayload($payload, $headerConfigs, $lineConfigs, $lineContainer);

			if ($entityType === 'dispatch') {
				$payload = $this->finalizeDispatchFulfillmentLineItemIds(
					$payload,
					(string) ($erpData['_ecom_order_id'] ?? $erpData['_push']['ecom_order_id'] ?? $pushContext['ecom_order_id'] ?? '')
				);
			}

			$payload = $this->pruneEmptyEcomPayloadValues($payload);

			return $payload;
		}

		return $this->buildEcomPayload($erpData, $fieldConfigs);
	}

	/** Build Ecom→ERP payload from field configs (no API call). */
	public function buildErpPayloadForEntity(string $entityType, array $ecomData, ?string $scope = null): array
	{
		$scope = $scope ?? ($entityType === 'inventory' ? 'default' : 'header');

		if ($entityType === 'customer') {
			return app(FieldMappingService::class)->buildErpCustomerPayload(
				$ecomData,
				$this->ecom->driverName(),
				$this->erp->driverName()
			);
		}

		if ($entityType === 'sales_order') {
			return $this->buildErpPayloadFull($entityType, $ecomData, $scope);
		}

		if ($entityType === 'dispatch') {
			return $this->buildErpPayloadFull($entityType, $ecomData, $scope);
		}

		if ($entityType === 'inventory') {
			return app(FieldMappingService::class)->buildEcomToErpInventoryPayload(
				$ecomData,
				$this->ecom->driverName(),
				$this->erp->driverName()
			);
		}

		$payload = $this->buildScopedErpPayload($entityType, $ecomData, $scope);

		return $payload;
	}

	// ── Public helper: ERP fields needed to satisfy configs for an entity ──
	// Use this to build the field list for ERP API calls (e.g. stock.move read)
	// instead of hardcoding field names in adapters.
	public function getErpFieldsToFetch(string $entityType, ?string $scope = null, string $direction = 'erp_to_ecom'): array
	{
		$configs = $this->getFieldConfigs($entityType, $scope, $direction);

		$fields = $configs
			->flatMap(fn($c) => array_filter([
				$c->erp_field   ? explode('.', $c->erp_field)[0]   : null,
				$c->erp_field_2 ? explode('.', $c->erp_field_2)[0] : null,
			]))
			->unique()
			->filter()
			->values()
			->toArray();

		$fields = $this->filterEnrichedFetchFieldRoots($entityType, $fields, $direction);

		return $this->erp->normalizeFetchFieldList($entityType, $fields, $direction);
	}

	/**
	 * Header-only write keys from a mapped payload — strips line containers and non-config fields.
	 *
	 * @return array<string, mixed>
	 */
	public function extractHeaderWritePayload(
		string $entityType,
		array $mappedPayload,
		string $direction = 'ecom_to_erp',
	): array {
		$container = $this->resolveLineContainer($entityType, $direction);
		if ($container !== null) {
			unset($mappedPayload[$container['erp_lines_key']]);
		}

		$allowedRoots = $this->getFieldConfigs($entityType, 'header', $direction)
			->map(fn ($c) => trim(explode('.', (string) ($c->erp_field ?? ''))[0]))
			->filter()
			->unique()
			->values()
			->all();

		$out = [];
		foreach ($mappedPayload as $key => $value) {
			if (str_starts_with((string) $key, '_')) {
				continue;
			}

			if (!in_array((string) $key, $allowedRoots, true)) {
				continue;
			}

			if ($value === null || $value === '') {
				continue;
			}

			$out[$key] = $value;
		}

		return $out;
	}

	/**
	 * Dispatch line field linking back to a sales order (from dispatch line field configs).
	 */
	public function dispatchSalesOrderLinkField(string $direction = 'ecom_to_erp'): ?string
	{
		foreach ($this->getFieldConfigs('dispatch', 'line', $direction) as $config) {
			$field = trim(explode('.', (string) ($config->erp_field ?? ''))[0]);
			if ($field !== '' && str_contains($field, 'sales_order')) {
				return $field;
			}
		}

		return $this->dispatchLineLinkFieldFromDb($direction);
	}

	/** @deprecated Use dispatchSalesOrderLinkField() */
	public function erpNextDeliveryNoteSalesOrderLinkField(string $direction = 'ecom_to_erp'): ?string
	{
		return $this->dispatchSalesOrderLinkField($direction);
	}

	/**
	 * Dispatch line field for sales-order line detail (so_detail, move_id, …).
	 */
	public function dispatchSalesOrderLineDetailField(string $direction = 'ecom_to_erp'): ?string
	{
		foreach ($this->getFieldConfigs('dispatch', 'line', $direction) as $config) {
			$ecom  = trim((string) ($config->ecom_field ?? ''));
			$field = strtolower(trim(explode('.', (string) ($config->erp_field ?? ''))[0]));

			if ($ecom === '_so_line' && $field !== '') {
				return $field;
			}

			if (($config->field_type ?? '') === 'custom' && $field === 'so_detail') {
				return 'so_detail';
			}
		}

		return $this->dispatchLineDetailFieldFromDb($direction);
	}

	/** @deprecated Use dispatchSalesOrderLineDetailField() */
	public function erpNextDeliveryNoteSalesOrderDetailField(string $direction = 'ecom_to_erp'): ?string
	{
		return $this->dispatchSalesOrderLineDetailField($direction);
	}

	/**
	 * Sales order line field that holds the ERP line id used on dispatch lines.
	 */
	public function salesOrderLineDetailSourceField(string $direction = 'ecom_to_erp'): ?string
	{
		foreach ($this->getFieldConfigs('sales_order', 'line', $direction) as $config) {
			$ecom  = trim((string) ($config->ecom_field ?? ''));
			$field = strtolower(trim(explode('.', (string) ($config->erp_field ?? ''))[0]));

			if ($ecom === '_so_line' && $field !== '') {
				return $field;
			}

			if (($config->field_type ?? '') === 'custom' && $field === 'name') {
				return 'name';
			}
		}

		return null;
	}

	/** @deprecated Use salesOrderLineDetailSourceField() */
	public function erpNextSalesOrderLineDetailSourceField(string $direction = 'ecom_to_erp'): ?string
	{
		return $this->salesOrderLineDetailSourceField($direction);
	}

	private function dispatchLineDetailFieldFromDb(string $direction = 'ecom_to_erp'): ?string
	{
		$settings = app(\App\Services\SettingsService::class);

		$config = ProductFieldConfig::query()
			->where('entity_type', 'dispatch')
			->where('ecom_driver', $settings->ecomDriver())
			->where('erp_driver', $settings->erpDriver())
			->where('scope', 'line')
			->where('is_active', true)
			->where(function ($q) use ($direction) {
				$q->where('direction', $direction)->orWhereNull('direction');
			})
			->where(function ($q) {
				$q->where('erp_field', 'so_detail')
					->orWhere('ecom_field', '_so_line');
			})
			->orderBy('sort_order')
			->first();

		if ($config === null) {
			return null;
		}

		$field = trim(explode('.', (string) ($config->erp_field ?? ''))[0]);

		return $field !== '' ? $field : null;
	}

	private function dispatchLineLinkFieldFromDb(string $direction = 'ecom_to_erp'): ?string
	{
		$settings = app(\App\Services\SettingsService::class);

		$config = ProductFieldConfig::query()
			->where('entity_type', 'dispatch')
			->where('ecom_driver', $settings->ecomDriver())
			->where('erp_driver', $settings->erpDriver())
			->where('scope', 'line')
			->where('is_active', true)
			->where(function ($q) use ($direction) {
				$q->where('direction', $direction)->orWhereNull('direction');
			})
			->where(function ($q) {
				$q->where('erp_field', 'against_sales_order')
					->orWhere('erp_field', 'like', '%sales_order%');
			})
			->orderBy('sort_order')
			->first();

		if ($config === null) {
			return null;
		}

		$field = trim(explode('.', (string) ($config->erp_field ?? ''))[0]);

		return $field !== '' ? $field : null;
	}

	/** @return list<string> */
	public function dispatchLineErpFieldRoots(string $direction = 'ecom_to_erp'): array
	{
		$roots = array_values(array_unique(array_filter(array_map(
			fn ($c) => trim(explode('.', (string) ($c->erp_field ?? ''))[0]),
			$this->getFieldConfigs('dispatch', 'line', $direction)->all()
		))));

		if ($roots !== []) {
			return $roots;
		}

		$settings = app(\App\Services\SettingsService::class);

		return ProductFieldConfig::query()
			->where('entity_type', 'dispatch')
			->where('ecom_driver', $settings->ecomDriver())
			->where('erp_driver', $settings->erpDriver())
			->where('scope', 'line')
			->where('is_active', true)
			->where(function ($q) use ($direction) {
				$q->where('direction', $direction)->orWhereNull('direction');
			})
			->pluck('erp_field')
			->map(fn ($f) => trim(explode('.', (string) $f)[0]))
			->filter()
			->unique()
			->values()
			->all();
	}

	/**
	 * Active sales_order line config erp_field roots.
	 *
	 * @return array<string, string>
	 */
	public function salesOrderLineFieldRoots(string $direction = 'ecom_to_erp'): array
	{
		$map = [];

		foreach ($this->getFieldConfigs('sales_order', 'line', $direction) as $config) {
			$root = trim(explode('.', (string) ($config->erp_field ?? ''))[0]);
			if ($root === '' || ($config->field_type ?? '') === 'custom') {
				continue;
			}

			$map[$root] = $root;
		}

		return $map;
	}

	/** @deprecated Use salesOrderLineFieldRoots() */
	public function erpNextSalesOrderLineFieldRoots(string $direction = 'ecom_to_erp'): array
	{
		return $this->salesOrderLineFieldRoots($direction);
	}

	/**
	 * ERP path roots that are enriched in cache / separate API calls — not Odoo ORM columns.
	 * e.g. vendors.* comes from product.supplierinfo via getVendorsForTemplate(), not template read.
	 *
	 * @param  list<string>  $fields
	 * @return list<string>
	 */
	private function filterEnrichedFetchFieldRoots(string $entityType, array $fields, string $direction = 'erp_to_ecom'): array
	{
		$skipRoots = match ($entityType) {
			'product' => ['vendors', '_primary_vendor', '_attribute_values'],
			default   => [],
		};

		if ($entityType === 'dispatch') {
			$container = $this->resolveLineContainer('dispatch', $direction);
			if ($container !== null) {
				$linesKey = $container['erp_lines_key'];
				// Enriched line rows are not stock.picking ORM columns
				if (!str_ends_with($linesKey, '_ids')) {
					$skipRoots[] = $linesKey;
				}
			}
		}

		return array_values(array_filter(
			$fields,
			fn ($f) => !in_array($f, $skipRoots, true) && !str_starts_with((string) $f, '_')
		));
	}

	/**
	 * Line-container row from field config (line_container transform, header scope).
	 *
	 * @return array{erp_lines_key: string, ecom_lines_key: string}|null
	 */
	public function resolveLineContainer(string $entityType, string $direction = 'erp_to_ecom'): ?array
	{
		foreach ($this->getFieldConfigs($entityType, 'header', $direction) as $config) {
			if (FieldMappingService::effectiveSystemTransform($config->transform, $config->reverse_transform) !== 'line_container') {
				continue;
			}

			$erpKey = trim(explode('.', (string) ($config->erp_field ?? ''))[0]);
			$ecomKey = trim(explode('.', (string) ($config->ecom_field ?? ''))[0]);
			if ($erpKey === '' || $ecomKey === '') {
				continue;
			}

			return [
				'erp_lines_key'  => $erpKey,
				'ecom_lines_key' => $ecomKey,
			];
		}

		foreach ($this->getFieldConfigs($entityType, 'header', $direction) as $config) {
			$erpKey  = trim(explode('.', (string) ($config->erp_field ?? ''))[0]);
			$ecomKey = trim(explode('.', (string) ($config->ecom_field ?? ''))[0]);
			if ($erpKey === '' || $ecomKey === '' || !str_ends_with($erpKey, '_ids')) {
				continue;
			}

			return [
				'erp_lines_key'  => $erpKey,
				'ecom_lines_key' => $ecomKey,
			];
		}

		return null;
	}

	/**
	 * @throws \RuntimeException when line-scope configs exist without a line_container row
	 */
	public function requireLineContainer(string $entityType, string $direction = 'erp_to_ecom'): ?array
	{
		$lineConfigs = $this->getFieldConfigs($entityType, 'line', $direction);
		$container   = $this->resolveLineContainer($entityType, $direction);

		if ($lineConfigs->isNotEmpty() && $container === null) {
			throw new \RuntimeException(
				"Field configs for {$entityType} include line mappings but no line_container row. "
				. 'Add a header field config with transform line_container: set erp_field to the ERP lines '
				. 'relation on the header record (e.g. move_ids) and ecom_field to the staging array key (e.g. line_items).'
			);
		}

		return $container;
	}

	/** Longest shared parent path among line-scope ecom_field dot paths. */
	private function resolveLineItemArrayPrefix(\Illuminate\Support\Collection $lineConfigs): string
	{
		$prefixes = [];
		foreach ($lineConfigs as $config) {
			$field = trim((string) ($config->ecom_field ?? ''));
			if ($field === '') {
				continue;
			}

			if (!str_contains($field, '.')) {
				$prefixes[] = $field;
				continue;
			}

			$parts = explode('.', $field);
			array_pop($parts);
			if ($parts !== []) {
				$prefixes[] = implode('.', $parts);
			}
		}

		if ($prefixes === []) {
			return '';
		}

		$counts = array_count_values($prefixes);
		uksort($counts, fn (string $a, string $b) => strlen($b) <=> strlen($a) ?: $counts[$b] <=> $counts[$a]);

		return array_key_first($counts);
	}

	/** Odoo one2many id field for a line container (moves → move_ids, move_ids → move_ids). */
	public function inferLineIdFieldName(string $linesKey): ?string
	{
		$linesKey = trim(explode('.', $linesKey)[0]);
		if ($linesKey === '') {
			return null;
		}

		if (str_ends_with($linesKey, '_ids')) {
			return $linesKey;
		}

		if (str_ends_with($linesKey, 's') && strlen($linesKey) > 1) {
			return substr($linesKey, 0, -1) . '_ids';
		}

		return $linesKey . '_ids';
	}

	/**
	 * Load full line records for the configured line_container erp field.
	 *
	 * @param  array<string, mixed>  $erpData
	 * @return array<string, mixed>
	 */
	public function enrichEntityLines(string $entityType, array $erpData): array
	{
		$lineConfigs = $this->getFieldConfigs($entityType, 'line', 'erp_to_ecom');
		$container   = $this->requireLineContainer($entityType);
		if ($container === null) {
			return $erpData;
		}

		$key   = $container['erp_lines_key'];
		$lines = $erpData[$key] ?? null;

		if (is_array($lines) && $lines !== [] && is_array($lines[0] ?? null)) {
			$erpData[$key] = array_values(array_map(
				fn ($row) => is_array($row) ? $this->normalizeDispatchLineRow($row) : $row,
				array_filter($lines, fn ($m) => is_array($m) && isset($m['id']))
			));

			return $erpData;
		}

		$ids = $this->extractLineRecordIds($key, $erpData);
		if ($ids === []) {
			$erpData[$key] = [];

			return $erpData;
		}

		try {
			$fetched = $this->erp->getMoves($ids);
			$erpData[$key] = array_values(array_map(
				fn ($row) => is_array($row) ? $this->normalizeDispatchLineRow($row) : $row,
				array_filter($fetched, fn ($m) => is_array($m) && isset($m['id']))
			));
		} catch (\Throwable $e) {
			Log::warning(
				"UniversalSyncService: enrichEntityLines failed for {$entityType}.{$key}: " . $e->getMessage()
			);
			$erpData[$key] = [];
		}

		return $erpData;
	}

	/**
	 * Extra stock.picking columns needed beyond fields_get (relation ids for line container).
	 *
	 * @return list<string>
	 */
	public function dispatchPickingRelationFields(string $direction = 'erp_to_ecom'): array
	{
		$container = $this->resolveLineContainer('dispatch', $direction);
		if ($container === null) {
			return [];
		}

		$fields = [];
		$linesKey = $container['erp_lines_key'];

		if (str_ends_with($linesKey, '_ids')) {
			$fields[] = $linesKey;

			return array_values(array_unique($fields));
		}

		$idField = $this->inferLineIdFieldName($linesKey);
		if ($idField !== null && $idField !== $linesKey && str_ends_with($idField, '_ids')) {
			$fields[] = $idField;
		}

		return array_values(array_unique($fields));
	}

	/**
	 * stock.picking columns to read — entirely from field configs + Odoo model introspection.
	 *
	 * @return list<string>
	 */
	public function buildDispatchPickingReadFields(string $direction = 'erp_to_ecom'): array
	{
		$fields = array_merge(
			$this->getErpFieldsToFetch('dispatch', 'header', $direction),
			$this->getErpFieldsToFetch('dispatch', 'line', $direction),
			$this->dispatchPickingRelationFields($direction),
			$this->discoverPickingOperationalReadFields(),
		);

		return array_values(array_unique($fields));
	}

	/**
	 * stock.move columns to read when applying dispatch lines.
	 *
	 * @return list<string>
	 */
	public function buildDispatchMoveReadFields(string $direction = 'ecom_to_erp'): array
	{
		return $this->getErpFieldsToFetch('dispatch', 'line', $direction);
	}

	/**
	 * Odoo search domain to locate the outgoing delivery for a sale order.
	 * Uses fields_get (many2one → sale.order, picking type) — no hardcoded field names.
	 *
	 * @return list<array{0: string, 1: string, 2: mixed}>
	 */
	public function buildOutgoingPickingSearchDomain(int $saleOrderId): array
	{
		$normalizer = app(\App\Services\Odoo\OdooFieldNormalizer::class);
		$defs       = $normalizer->getFieldDefinitions('stock.picking');
		$domain     = [];

		foreach ($defs as $name => $def) {
			if (($def['type'] ?? '') === 'many2one' && ($def['relation'] ?? '') === 'sale.order') {
				$domain[] = [$name, '=', $saleOrderId];
				break;
			}
		}

		if (isset($defs['picking_type_code'])) {
			$domain[] = ['picking_type_code', '=', 'outgoing'];
		}

		return $domain;
	}

	/**
	 * Extract ORM create tuples from a mapped dispatch payload line container.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function extractDispatchLinePayloads(array $mappedPayload, string $direction = 'ecom_to_erp'): array
	{
		$container = $this->resolveLineContainer('dispatch', $direction);
		if ($container === null) {
			return [];
		}

		$commands = $mappedPayload[$container['erp_lines_key']] ?? [];
		if (!is_array($commands)) {
			return [];
		}

		$rows = [];
		foreach ($commands as $command) {
			if (!is_array($command) || count($command) < 3) {
				continue;
			}
			if ((int) ($command[0] ?? -1) !== 0 || (int) ($command[1] ?? -1) !== 0) {
				continue;
			}
			if (!is_array($command[2]) || $command[2] === []) {
				continue;
			}
			$rows[] = $command[2];
		}

		return $rows;
	}

	/**
	 * Match an existing stock.move row to a field-config line payload (by product many2one).
	 */
	public function dispatchMoveMatchesLinePayload(
		array $move,
		array $linePayload,
		string $direction = 'ecom_to_erp'
	): bool {
		$normalizer  = app(\App\Services\Odoo\OdooFieldNormalizer::class);
		$lineConfigs = $this->getFieldConfigs('dispatch', 'line', $direction);

		foreach ($lineConfigs as $config) {
			$erpRoot = trim(explode('.', (string) ($config->erp_field ?? ''))[0]);
			if ($erpRoot === '') {
				continue;
			}

			$defs = $normalizer->getFieldDefinitions('stock.move');
			$type = $defs[$erpRoot]['type'] ?? null;
			if ($type !== 'many2one') {
				continue;
			}

			$moveId  = $normalizer->extractMany2OneId($move[$erpRoot] ?? null);
			$lineId  = $normalizer->extractMany2OneId($linePayload[$erpRoot] ?? null);

			if ($moveId !== null && $lineId !== null && (int) $moveId === (int) $lineId) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Fields on stock.picking needed for connector workflow (sale link, state, type) — from fields_get only.
	 *
	 * @return list<string>
	 */
	private function discoverPickingOperationalReadFields(): array
	{
		$normalizer = app(\App\Services\Odoo\OdooFieldNormalizer::class);
		$defs       = $normalizer->getFieldDefinitions('stock.picking');
		$fields     = [];

		foreach ($defs as $name => $def) {
			if (($def['type'] ?? '') === 'many2one' && ($def['relation'] ?? '') === 'sale.order') {
				$fields[] = $name;
			}
		}

		return array_values(array_unique($fields));
	}

	/** @param  array<string, mixed>  $erpData  @return list<int|string> */
	private function extractLineRecordIds(string $linesKey, array $erpData): array
	{
		$raw = $erpData[$linesKey] ?? null;
		if (is_array($raw) && $raw !== [] && !is_array($raw[0] ?? null)) {
			return array_values(array_filter(
				$raw,
				fn ($id) => is_int($id) || (is_string($id) && ctype_digit((string) $id))
			));
		}

		$idKey = $this->inferLineIdFieldName($linesKey);
		if ($idKey === null || !isset($erpData[$idKey])) {
			return [];
		}

		$ids = $erpData[$idKey];
		if (!is_array($ids)) {
			return [];
		}

		return array_values(array_filter(
			$ids,
			fn ($id) => is_int($id) || (is_string($id) && ctype_digit((string) $id))
		));
	}

	// ── Field config loader ───────────────────────────────────────────────

	private function getFieldConfigs(string $entityType, ?string $scope, ?string $direction = null): \Illuminate\Support\Collection
	{
		$ecomDriver = $this->ecom->driverName();
		$erpDriver  = $this->erp->driverName();
		$cacheKey   = "field_configs_{$entityType}_{$ecomDriver}_{$erpDriver}_{$scope}_{$direction}";

		return Cache::remember($cacheKey, 300, function () use ($entityType, $scope, $ecomDriver, $erpDriver, $direction) {
			$query = ProductFieldConfig::where('entity_type', $entityType)
				->where('ecom_driver', $ecomDriver)
				->where('erp_driver', $erpDriver)
				->where('is_active', true)
				->ordered();

			if ($scope) {
				$query->where('scope', $scope);
			}

			// Direction separation (mirrors the product reader):
			//   'erp_to_ecom' → existing rows (NULL or 'erp_to_ecom'); never the
			//                   new ecom→erp set, so erp→ecom is unaffected.
			//   'ecom_to_erp' → strictly the ecom→erp set.
			if ($direction === 'erp_to_ecom') {
				$query->where(function ($q) {
					$q->whereNull('direction')->orWhere('direction', '!=', 'ecom_to_erp');
				});
			} elseif ($direction === 'ecom_to_erp') {
				if (in_array($entityType, ['customer', 'dispatch'], true)) {
					// Legacy rows pre-date direction; still valid for ecom→erp dispatch/customer.
					$query->where(function ($q) {
						$q->where('direction', 'ecom_to_erp')->orWhereNull('direction');
					});
				} else {
					$query->where('direction', 'ecom_to_erp');
				}
			}

			return $query->get();
		});
	}

	// ── Payload builders ──────────────────────────────────────────────────

	/** Flat-scope Ecom→ERP payload (inventory, simple entities). */
	private function buildScopedErpPayload(string $entityType, array $ecomData, string $scope): array
	{
		$configs = $this->getFieldConfigs($entityType, $scope, 'ecom_to_erp');

		return app(FieldMappingService::class)->buildGenericEcomToErpPayload(
			$configs,
			$ecomData,
			$ecomData,
			$this->ecom->driverName(),
			$this->erp->driverName()
		);
	}

	/**
	 * ERP → Ecom payload builder.
	 */
	private function buildEcomPayload(array $erpData, \Illuminate\Support\Collection $fieldConfigs): array
	{
		$payload = [];
		$mapper  = app(FieldMappingService::class);

		foreach ($fieldConfigs as $config) {
			if ($config->field_type === 'custom') {
				$value = $config->default_value;
			} elseif ($config->field_type === 'combine') {
				$value = $mapper->resolveCombineValue($config, $erpData, $erpData, 'erp_to_ecom');
				if ($value === '') {
					$value = $config->default_value;
				}
			} else {
				$value = $this->getNestedValue($erpData, $config->erp_field ?? '');
				if ($value === null) {
					$value = $config->default_value;
				}
			}

			$systemTransform = $this->resolveConfiguredTransform($config);
			if (!$this->shouldSkipMany2OneCoalesce($systemTransform, $config->erp_field ?? '')) {
				$value = $this->coalesceMany2OneDisplay($value, $config->erp_field ?? '');
			}

			if ($value !== null && !empty($config->conditions)) {
				$value = app(ValueConditionMapper::class)->apply(
					$value,
					$config->conditions,
					$config->default_value,
				);
			}

			$contextTransforms = ['resolve_fulfillment_order_id', 'resolve_fulfillment_line_item_id'];
			if ($systemTransform && ($value !== null || in_array($systemTransform, $contextTransforms, true))) {
				$transformed = $mapper->applySystemTransform($value, $systemTransform, $erpData, null, 'erp_to_ecom');
				if (in_array($systemTransform, $contextTransforms, true)) {
					if ($transformed === null || $transformed === '') {
						throw new \RuntimeException(
							"Could not resolve {$systemTransform} for Shopify order "
							. ($erpData['_ecom_order_id'] ?? $erpData['_push']['ecom_order_id'] ?? '?') . '.'
						);
					}
					$value = $transformed;
				} elseif ($transformed !== null) {
					$value = $transformed;
				}
			}

			$value = $mapper->shapeEcomOutput($value, $config);

			if ($value !== null && $value !== '') {
				$ecomField = (string) ($config->ecom_field ?? '');
				if (str_contains($ecomField, '.')) {
					$this->fields->set($payload, $ecomField, $value);
				} else {
					$payload[$ecomField] = $value;
				}
			}
		}

		return $payload;
	}

	/**
	 * ERP → Ecom payload builder with header + line scopes (dispatch, sales_order).
	 */
	private function buildEcomPayloadFull(string $entityType, array $erpData): array
	{
		$headerConfigs = $this->getFieldConfigs($entityType, 'header', 'erp_to_ecom');
		$lineConfigs   = $this->getFieldConfigs($entityType, 'line', 'erp_to_ecom');

		if ($headerConfigs->isEmpty() && $lineConfigs->isEmpty()) {
			return [];
		}

		$erpLinesKey  = null;
		$ecomLinesKey = null;

		$lineContainer = $this->resolveLineContainer($entityType);
		if ($lineContainer !== null) {
			$erpLinesKey  = $lineContainer['erp_lines_key'];
			$ecomLinesKey = $lineContainer['ecom_lines_key'];
		}

		$headerOnly = $headerConfigs->filter(
			fn ($c) => FieldMappingService::effectiveSystemTransform($c->transform, null) !== 'line_container'
		);
		$payload    = $this->buildEcomPayload($erpData, $headerOnly);

		if ($lineConfigs->isNotEmpty() && $erpLinesKey === null) {
			throw new \RuntimeException(
				"No line_container for {$entityType}. Add a header field config with transform line_container "
				. '(erp_field = ERP lines relation on the header record, ecom_field = staging array key).'
			);
		}

		if ($lineConfigs->isNotEmpty() && $erpLinesKey && !empty($erpData[$erpLinesKey]) && is_array($erpData[$erpLinesKey])) {
			if ($entityType === 'sales_order') {
				$this->assertValidSalesOrderLineFieldConfigs($lineConfigs, $ecomLinesKey);
			}

			$ecomLines = [];

			foreach ($erpData[$erpLinesKey] as $line) {
				if (!is_array($line)) {
					continue;
				}

				$lineContext = array_merge($line, [
					'_ecom_order_id' => $erpData['_ecom_order_id'] ?? ($erpData['_push']['ecom_order_id'] ?? null),
					'_push'          => array_merge(
						is_array($erpData['_push'] ?? null) ? $erpData['_push'] : [],
						[
							'ecom_order_id' => $erpData['_ecom_order_id'] ?? ($erpData['_push']['ecom_order_id'] ?? null),
							'erp_order_id'  => $erpData['erp_order_id']
								?? (is_array($erpData['sale_id'] ?? null) ? $erpData['sale_id'][0] : ($erpData['sale_id'] ?? null)),
						]
					),
				]);

				$built = $this->buildSingleEcomLinePayload(
					$lineContext,
					$lineConfigs,
					$ecomLinesKey,
					$entityType === 'sales_order'
				);
				if (!empty($built)) {
					$ecomLines[] = $built;
				}
			}

			if (!empty($ecomLines)) {
				if (str_contains($ecomLinesKey, '.')) {
					$this->fields->set($payload, $ecomLinesKey, $ecomLines);
				} else {
					$payload[$ecomLinesKey] = $ecomLines;
				}
			} elseif ($lineConfigs->isNotEmpty()) {
				Log::warning(
					"UniversalSyncService: {$entityType} line mappings produced no line items — check line_container erp field and line-scope mappings.",
					[
						'erp_lines_key'  => $erpLinesKey,
						'ecom_lines_key' => $ecomLinesKey,
						'line_count'     => is_array($erpData[$erpLinesKey] ?? null)
							? count($erpData[$erpLinesKey])
							: 0,
					]
				);
			}
		}

		return $payload;
	}

	private function buildSingleEcomLinePayload(
		array $lineData,
		\Illuminate\Support\Collection $lineConfigs,
		string $ecomLinesKey,
		bool $strictLinePathValidation = true
	): array {
		$payload = [];
		$mapper  = app(FieldMappingService::class);

		foreach ($lineConfigs as $config) {
			$rawEcomField = trim($config->ecom_field ?? '');
			$ecomField    = $this->resolveLineItemEcomFieldPath($rawEcomField, $ecomLinesKey, $lineConfigs);

			if ($rawEcomField !== '' && $ecomField === null) {
				if ($strictLinePathValidation) {
					throw new \RuntimeException(
						"Line ecom_field \"{$rawEcomField}\" does not match line container \"{$ecomLinesKey}\". "
						. "Use {$ecomLinesKey}.fieldName or a configured parent prefix from line-scope field configs."
					);
				}

				continue;
			}

			if ($config->field_type === 'custom') {
				$value = $config->default_value;
			} elseif ($config->field_type === 'combine') {
				$value = $mapper->resolveCombineValue($config, $lineData, $lineData, 'erp_to_ecom');
				if ($value === '') {
					$value = $config->default_value;
				}
			} else {
				$value = $this->getNestedValue($lineData, $config->erp_field ?? '');
				if ($value === null) {
					$value = $config->default_value;
				}
			}

			$systemTransform = $this->resolveConfiguredTransform($config, $ecomField);

			if ($this->isFulfillmentLineItemIdMapping($config, $ecomField)) {
				$systemTransform = 'resolve_fulfillment_line_item_id';
				$value           = $this->getNestedValue($lineData, 'product_id');
			} elseif (trim((string) ($config->erp_field ?? '')) === 'id' && $ecomField === 'id') {
				throw new \RuntimeException(
					'Invalid dispatch line mapping: stock.move id cannot be used as fulfillment line item id. '
					. 'Map product_id to fulfillmentOrderLineItems.id with transform resolve_fulfillment_line_item_id.'
				);
			}

			if (!$this->shouldSkipMany2OneCoalesce($systemTransform, $config->erp_field ?? '')) {
				$value = $this->coalesceMany2OneDisplay($value, $config->erp_field ?? '');
			}

			if ($value !== null && !empty($config->conditions)) {
				$value = app(ValueConditionMapper::class)->apply(
					$value,
					$config->conditions,
					$config->default_value,
				);
			}

			$contextTransforms = ['resolve_fulfillment_order_id', 'resolve_fulfillment_line_item_id'];
			if ($systemTransform && ($value !== null || in_array($systemTransform, $contextTransforms, true))) {
				$transformed = $mapper->applySystemTransform($value, $systemTransform, $lineData, null, 'erp_to_ecom');
				if (in_array($systemTransform, $contextTransforms, true)) {
					if ($transformed === null || $transformed === '') {
						$odooRef = $this->getNestedValue($lineData, $config->erp_field ?? '');
						$odooId  = is_array($odooRef) ? ($odooRef[0] ?? '?') : ($odooRef ?? '?');
						throw new \RuntimeException(
							"Could not resolve {$systemTransform} for Odoo product_id {$odooId}. "
							. 'Ensure the product is synced to Shopify (SyncMapping with a Shopify variant id) '
							. 'and the product appears on this order.'
						);
					}
					$value = $transformed;
				} elseif ($transformed !== null) {
					$value = $transformed;
				}
			}

			if ($value !== null && is_numeric($value) && str_contains(strtolower((string) ($config->ecom_field ?? '')), 'quantity')) {
				$value = (int) round((float) $value);
			}

			if ($systemTransform === 'skip' || $value === null || $ecomField === '') {
				continue;
			}

			$value = $mapper->shapeEcomOutput($value, $config);

			if ($value !== null) {
				if (str_contains($ecomField, '.')) {
					$this->fields->set($payload, $ecomField, $value);
				} else {
					$payload[$ecomField] = $value;
				}
			}
		}

		return $payload;
	}

	/**
	 * Line-scope ecom_field paths must use the configured line container prefix
	 * (e.g. lineItems.quantity) — not an arbitrary prefix like line_items1.quantity.
	 */
	private function resolveLineItemEcomFieldPath(
		string $ecomField,
		string $ecomLinesKey,
		\Illuminate\Support\Collection $lineConfigs
	): ?string {
		$ecomField = trim($ecomField);
		if ($ecomField === '') {
			return '';
		}

		if (!str_contains($ecomField, '.')) {
			return $ecomField;
		}

		foreach ($this->lineItemContainerPrefixes($ecomLinesKey, $lineConfigs) as $prefix) {
			$needle = $prefix . '.';
			if (str_starts_with($ecomField, $needle)) {
				return substr($ecomField, strlen($needle));
			}
		}

		return null;
	}

	/** @return list<string> */
	private function lineItemContainerPrefixes(
		string $ecomLinesKey,
		\Illuminate\Support\Collection $lineConfigs
	): array {
		$prefixes = [$ecomLinesKey];

		foreach ($lineConfigs as $config) {
			$field = trim((string) ($config->ecom_field ?? ''));
			if ($field === '' || !str_contains($field, '.')) {
				if ($field !== '') {
					$prefixes[] = $field;
				}
				continue;
			}

			$parts = explode('.', $field);
			array_pop($parts);
			while ($parts !== []) {
				$prefixes[] = implode('.', $parts);
				array_pop($parts);
			}
		}

		usort($prefixes, fn (string $a, string $b) => strlen($b) <=> strlen($a));

		return array_values(array_unique($prefixes));
	}

	private function assertValidSalesOrderLineFieldConfigs(
		\Illuminate\Support\Collection $lineConfigs,
		string $ecomLinesKey
	): void {
		foreach ($lineConfigs as $config) {
			$rawEcomField = trim($config->ecom_field ?? '');
			if ($rawEcomField === '' || !str_contains($rawEcomField, '.')) {
				continue;
			}

			if ($this->resolveLineItemEcomFieldPath($rawEcomField, $ecomLinesKey, $lineConfigs) === null) {
				throw new \RuntimeException(
					"Sales order line field config \"{$rawEcomField}\" is invalid. "
					. "Line fields must use the configured line container prefix \"{$ecomLinesKey}.\" "
					. 'or another parent prefix defined in line-scope field configs.'
				);
			}
		}
	}

	/**
	 * Product variant field configs map inventoryQuantities.availableQuantity from qty_available.
	 * Odoo often stores qty on stock.quant, not on the variant row — fill from quant when missing.
	 *
	 * @param  array<string, mixed>  $ecomPayload
	 * @param  array<string, mixed>  $template
	 * @return array<string, mixed>
	 */
	private function enrichProductVariantInventoryQuantities(array $ecomPayload, array $template): array
	{
		$variants = $ecomPayload['variants'] ?? [];
		if ($variants === []) {
			return $ecomPayload;
		}

		$qty = $this->resolveTemplateAvailableQty($template);
		if ($qty === null) {
			return $ecomPayload;
		}

		foreach ($variants as $index => $variant) {
			if (!is_array($variant)) {
				continue;
			}

			$current = $this->fields->get($variant, 'inventoryQuantities.availableQuantity')
				?? $this->fields->get($variant, 'inventoryQuantities.quantity');

			if ($current !== null && $current !== '') {
				continue;
			}

			$this->fields->set($variants[$index], 'inventoryQuantities.availableQuantity', $qty);
		}

		$ecomPayload['variants'] = $variants;

		return $ecomPayload;
	}

	/** @param  array<string, mixed>  $template */
	private function resolveTemplateAvailableQty(array $template): ?int
	{
		foreach (['qty_available', 'virtual_available', 'available', 'quantity'] as $key) {
			if (isset($template[$key]) && $template[$key] !== '' && $template[$key] !== null) {
				return (int) $template[$key];
			}
		}

		$productId = $template['id'] ?? null;
		if ($productId === null || $productId === '') {
			return null;
		}

		try {
			$quants = $this->erp->getInventoryModifiedSince('2000-01-01 00:00:00');
			$quant  = app(InventorySyncService::class)->resolveQuantForErpProduct($quants, $productId);

			if ($quant === null) {
				return null;
			}

			$enriched = $this->enrichInventoryQuantData($quant);

			return (int) ($enriched['available_quantity'] ?? $enriched['qty_available'] ?? $enriched['available'] ?? 0);
		} catch (\Throwable $e) {
			Log::warning('UniversalSyncService: could not resolve product qty from stock.quant: ' . $e->getMessage());

			return null;
		}
	}

	/**
	 * stock.quant often stores on-hand as quantity/reserved_quantity while field
	 * configs may reference available_quantity or qty_available aliases.
	 */
	private function enrichInventoryQuantData(array $quant): array
	{
		$available = (int) max(
			0,
			(float) ($quant['quantity'] ?? 0) - (float) ($quant['reserved_quantity'] ?? 0)
		);

		foreach (['available_quantity', 'qty_available', 'available'] as $alias) {
			if (!array_key_exists($alias, $quant) || $quant[$alias] === null || $quant[$alias] === '') {
				$quant[$alias] = $available;
			}
		}

		return $quant;
	}

	/**
	 * Resolve any remaining Odoo product_id values on fulfillment line items to Shopify GIDs.
	 *
	 * @param  array<string, mixed>  $payload
	 * @return array<string, mixed>
	 */
	private function finalizeDispatchFulfillmentLineItemIds(array $payload, string $ecomOrderId): array
	{
		if ($ecomOrderId === '') {
			return $payload;
		}

		$groups = $payload['lineItemsByFulfillmentOrder'] ?? null;
		if (!is_array($groups)) {
			return $payload;
		}

		if (!array_is_list($groups)) {
			$payload['lineItemsByFulfillmentOrder'] = [$groups];
		}

		$service = app(\App\Services\Shopify\ShopifyFulfillmentService::class);

		foreach ($payload['lineItemsByFulfillmentOrder'] as $groupIndex => $group) {
			if (!is_array($group)) {
				continue;
			}

			$items = $group['fulfillmentOrderLineItems'] ?? null;
			if (!is_array($items)) {
				continue;
			}

			foreach ($items as $itemIndex => $item) {
				if (!is_array($item)) {
					continue;
				}

				$rawId = $item['id'] ?? null;
				if ($rawId === null || $rawId === '') {
					continue;
				}

				if (is_string($rawId) && str_starts_with($rawId, 'gid://shopify/FulfillmentOrderLineItem/')) {
					continue;
				}

				$resolved = $service->resolveFulfillmentOrderLineItemId($ecomOrderId, $rawId);
				if ($resolved === null) {
					$odooId = is_array($rawId) ? ($rawId[0] ?? '?') : $rawId;
					throw new \RuntimeException(
						"Could not resolve Shopify fulfillment line item for Odoo product_id {$odooId}. "
						. 'Sync the product to Shopify (SyncMapping with a variant id) or ensure the Odoo '
						. 'product SKU matches the Shopify order line.'
					);
				}

				$payload['lineItemsByFulfillmentOrder'][$groupIndex]['fulfillmentOrderLineItems'][$itemIndex]['id'] = $resolved;
			}
		}

		return $payload;
	}

	private function isFulfillmentLineItemIdMapping(ProductFieldConfig $config, string $resolvedEcomField): bool
	{
		if (trim((string) ($config->erp_field ?? '')) !== 'product_id') {
			return false;
		}

		$ecom = strtolower(trim((string) ($config->ecom_field ?? '')));
		$leaf = strtolower(trim($resolvedEcomField));

		return $leaf === 'id'
			|| str_ends_with($ecom, '.id')
			|| str_contains($ecom, 'fulfillmentorderlineitems');
	}

	/**
	 * Assemble flat staged line arrays into nested wire paths derived from field configs.
	 *
	 * @param  array<string, mixed>  $payload
	 * @param  array{erp_lines_key: string, ecom_lines_key: string}|null  $lineContainer
	 * @return array<string, mixed>
	 */
	private function assembleNestedEcomPayload(
		array $payload,
		\Illuminate\Support\Collection $headerConfigs,
		\Illuminate\Support\Collection $lineConfigs,
		?array $lineContainer
	): array {
		$structure = $this->resolveNestedWireStructure($lineContainer, $lineConfigs);
		if ($structure === null || $structure['group_path'] === '') {
			return $payload;
		}

		$stagingKey    = $structure['staging_key'];
		$wireRoot      = $structure['wire_root'];
		$lineArrayKey  = $structure['line_array_key'];
		$groupMetaKeys = $this->groupLevelFieldKeys($headerConfigs, $lineConfigs, $structure);
		$lineLeafKeys  = array_values(array_diff(
			$this->lineItemLeafKeys($lineConfigs, $stagingKey),
			$groupMetaKeys
		));

		$orphanLines = null;
		if ($stagingKey !== '' && !empty($payload[$stagingKey]) && is_array($payload[$stagingKey])) {
			$orphanLines = $payload[$stagingKey];
			unset($payload[$stagingKey]);
		}

		$existingGroup = $payload[$wireRoot] ?? null;
		if (is_array($existingGroup) && array_is_list($existingGroup)) {
			$existingGroup = $existingGroup[0] ?? [];
		}
		if (!is_array($existingGroup)) {
			$existingGroup = [];
		}

		if ($orphanLines !== null) {
			[$orphanLines, $groupMetaFromLines] = $this->stripGroupMetaFromLineItems($orphanLines, $groupMetaKeys);
			$orphanLines = $this->filterConfiguredLineItems($orphanLines, $lineLeafKeys);

			foreach ($groupMetaFromLines as $key => $value) {
				if (!array_key_exists($key, $existingGroup) || $existingGroup[$key] === null || $existingGroup[$key] === '') {
					$existingGroup[$key] = $value;
				}
			}

			$existingItems = $existingGroup[$lineArrayKey] ?? null;
			if ($existingItems === null || $existingItems === []) {
				$existingGroup[$lineArrayKey] = $orphanLines !== [] ? $orphanLines : null;
			}
		}

		if ($existingGroup === []) {
			return $payload;
		}

		$payload[$wireRoot] = [$existingGroup];

		foreach ($payload[$wireRoot] as $index => $group) {
			if (!is_array($group)) {
				continue;
			}

			if (!empty($group[$lineArrayKey]) && is_array($group[$lineArrayKey])) {
				[$items, $groupMetaFromLines] = $this->stripGroupMetaFromLineItems($group[$lineArrayKey], $groupMetaKeys);
				$items = $this->filterConfiguredLineItems($items, $lineLeafKeys);
				$payload[$wireRoot][$index][$lineArrayKey] = $items !== [] ? $items : null;

				foreach ($groupMetaFromLines as $key => $value) {
					if (empty($payload[$wireRoot][$index][$key])) {
						$payload[$wireRoot][$index][$key] = $value;
					}
				}
			} elseif (($group[$lineArrayKey] ?? null) === []) {
				$payload[$wireRoot][$index][$lineArrayKey] = null;
			}
		}

		return $payload;
	}

	/**
	 * @return array{
	 *   staging_key: string,
	 *   line_item_prefix: string,
	 *   line_array_key: string,
	 *   group_path: string,
	 *   wire_root: string,
	 * }|null
	 */
	private function resolveNestedWireStructure(
		?array $lineContainer,
		\Illuminate\Support\Collection $lineConfigs
	): ?array {
		$lineItemPrefix = $this->resolveLineItemArrayPrefix($lineConfigs);
		if ($lineItemPrefix === '') {
			return null;
		}

		$stagingKey = $lineContainer['ecom_lines_key'] ?? $lineItemPrefix;
		$parts      = explode('.', $lineItemPrefix);
		$lineArrayKey = array_pop($parts);
		$groupPath    = implode('.', $parts);

		if ($groupPath === '' || $lineArrayKey === '') {
			return null;
		}

		return [
			'staging_key'      => $stagingKey,
			'line_item_prefix' => $lineItemPrefix,
			'line_array_key'   => $lineArrayKey,
			'group_path'       => $groupPath,
			'wire_root'        => explode('.', $groupPath)[0],
		];
	}

	/** @return list<string> */
	private function groupLevelFieldKeys(
		\Illuminate\Support\Collection $headerConfigs,
		\Illuminate\Support\Collection $lineConfigs,
		array $structure
	): array {
		$keys        = [];
		$groupPrefix = $structure['group_path'] . '.';
		$linePrefix  = $structure['line_item_prefix'] . '.';

		foreach ($headerConfigs->merge($lineConfigs) as $config) {
			if (FieldMappingService::effectiveSystemTransform($config->transform, null) === 'line_container') {
				continue;
			}

			$field = trim((string) ($config->ecom_field ?? ''));
			if ($field === '' || !str_starts_with($field, $groupPrefix)) {
				continue;
			}
			if (str_starts_with($field, $linePrefix)) {
				continue;
			}

			$relative = substr($field, strlen($groupPrefix));
			if ($relative !== '' && !str_contains($relative, '.')) {
				$keys[] = $relative;
			}
		}

		return array_values(array_unique($keys));
	}

	/** @return list<string> */
	private function lineItemLeafKeys(
		\Illuminate\Support\Collection $lineConfigs,
		string $ecomLinesKey
	): array {
		$keys = [];
		foreach ($lineConfigs as $config) {
			$field = trim((string) ($config->ecom_field ?? ''));
			$path  = $this->resolveLineItemEcomFieldPath($field, $ecomLinesKey, $lineConfigs);
			if ($path !== null && $path !== '' && !str_contains($path, '.')) {
				$keys[] = $path;
			}
		}

		return array_values(array_unique($keys));
	}

	/**
	 * @param  array<int, mixed>  $items
	 * @param  list<string>  $lineLeafKeys
	 * @return array<int, array<string, mixed>>
	 */
	private function filterConfiguredLineItems(array $items, array $lineLeafKeys): array
	{
		if ($lineLeafKeys === []) {
			return array_values(array_filter($items, fn ($item) => is_array($item) && $item !== []));
		}

		$filtered = [];
		foreach ($items as $item) {
			if (!is_array($item)) {
				continue;
			}

			$row      = [];
			$complete = true;
			foreach ($lineLeafKeys as $key) {
				if (!array_key_exists($key, $item) || $item[$key] === null || $item[$key] === '') {
					$complete = false;
					break;
				}

				$value = $item[$key];
				if (is_numeric($value) && str_contains(strtolower($key), 'quantity')) {
					$value = (int) round((float) $value);
					if ($value <= 0) {
						$complete = false;
						break;
					}
				}
				$row[$key] = $value;
			}

			if ($complete) {
				$filtered[] = $row;
			}
		}

		return $filtered;
	}

	/**
	 * @param  array<int, mixed>  $lines
	 * @param  list<string>  $groupMetaKeys
	 * @return array{0: array<int, array<string, mixed>>, 1: array<string, mixed>}
	 */
	private function stripGroupMetaFromLineItems(array $lines, array $groupMetaKeys): array
	{
		$meta    = [];
		$cleaned = [];

		foreach ($lines as $line) {
			if (!is_array($line)) {
				continue;
			}

			foreach ($groupMetaKeys as $key) {
				if (!array_key_exists($key, $line)) {
					continue;
				}
				if (!array_key_exists($key, $meta) && $line[$key] !== null && $line[$key] !== '') {
					$meta[$key] = $line[$key];
				}
				unset($line[$key]);
			}

			$cleaned[] = $line;
		}

		return [$cleaned, $meta];
	}

	/** @param  array<string, mixed>  $line */
	private function normalizeDispatchLineRow(array $line): array
	{
		return $line;
	}

	/** Drop empty strings / empty arrays so partial configs cannot push blank payloads. */
	private function pruneEmptyEcomPayloadValues(array $payload): array
	{
		return array_filter($payload, function ($value) {
			if (is_bool($value)) {
				return true;
			}

			if (is_array($value)) {
				return $value !== [];
			}

			return $value !== null && $value !== '' && $value !== false;
		});
	}

	/**
	 * Ecom → ERP payload builder.
	 *
	 * Automatically detects item-level fields by inspecting ecom_field:
	 * if the root segment of the dot-path resolves to an array in ecomData,
	 * the config is treated as a line-item field — no extra DB column needed.
	 *
	 * Example:
	 *   ecom_field = "line_items.price_set.presentment_money.amount"
	 *   → root "line_items" is an array → item-level
	 *   → resolved per item as "price_set.presentment_money.amount"
	 *
	 * The ERP container field name comes from the row with transform = "line_container".
	 * Falls back to "order_line" if none exists.
	 */
	private function buildErpPayloadFull(string $entityType, array $ecomData, string $scope): array
	{
		$headerConfigs    = $this->getFieldConfigs($entityType, 'header', 'ecom_to_erp');
		$lineConfigs      = $this->getFieldConfigs($entityType, 'line', 'ecom_to_erp');

	$lineItemsKey = null;
	$erpLineField = 'order_line';

	// ── Find line_container row from header configs ────────────────────
	$mapper = app(FieldMappingService::class);
	foreach ($headerConfigs as $config) {
		$transform = FieldMappingService::effectiveSystemTransform($config->transform, $config->reverse_transform);
		if ($transform === 'line_container') {
			$erpLineField = trim(explode('.', (string) ($config->erp_field ?? ''))[0]) ?: 'order_line';
			$containerEcom = trim(explode('.', (string) ($config->ecom_field ?? ''))[0]);
			if ($containerEcom !== '') {
				$lineItemsKey = $mapper->resolveEcomRootKey($ecomData, $containerEcom) ?? $containerEcom;
			}
			break;
		}
	}

	// Heuristic: header row mapping an ecom array → erp *_ids relation acts as line container
	if ($lineItemsKey === null) {
		foreach ($headerConfigs as $config) {
			$erpRoot  = trim(explode('.', (string) ($config->erp_field ?? ''))[0]);
			$ecomRoot = trim(explode('.', (string) ($config->ecom_field ?? ''))[0]);
			if ($erpRoot === '' || $ecomRoot === '' || !str_ends_with($erpRoot, '_ids')) {
				continue;
			}
			$resolved = $mapper->resolveEcomRootKey($ecomData, $ecomRoot);
			if ($resolved === null) {
				continue;
			}
			$erpLineField = $erpRoot;
			$lineItemsKey = $resolved;
			break;
		}
	}

	// ── Detect line items array key from first line-scope config ──────
	foreach ($lineConfigs as $config) {
		$ecomField = (string) ($config->ecom_field ?? '');
		$root      = explode('.', $ecomField)[0];

		if ($root !== '') {
			$resolved = $mapper->resolveEcomRootKey($ecomData, $root);
			if ($resolved !== null) {
				$lineItemsKey = $resolved;
				break;
			}
		}

		// Nested line paths e.g. fulfillmentLineItems.* on flattened fulfillment record
		if (str_contains($ecomField, '.')) {
			$parts = explode('.', $ecomField);
			foreach ($parts as $i => $part) {
				if (!ctype_digit($part)) {
					continue;
				}
				$candidate = implode('.', array_slice($parts, 0, $i));
				if ($candidate !== '' && isset($ecomData[$candidate]) && is_array($ecomData[$candidate])) {
					$lineItemsKey = $candidate;
					break 2;
				}
			}
		}
	}

	// Fulfillment line items may be stored as a list under fulfillmentLineItems
	if ($lineItemsKey === null && isset($ecomData['fulfillmentLineItems']) && is_array($ecomData['fulfillmentLineItems'])) {
		$lineItemsKey = 'fulfillmentLineItems';
	}

	$payload = [];

	// ── Header fields ─────────────────────────────────────────────────
	$headerOnly = $headerConfigs->filter(function ($c) use ($lineItemsKey, $erpLineField) {
		if (FieldMappingService::effectiveSystemTransform($c->transform, $c->reverse_transform) === 'line_container') {
			return false;
		}

		if ($lineItemsKey !== null) {
			$ecomRoot = trim(explode('.', (string) ($c->ecom_field ?? ''))[0]);
			if ($ecomRoot === $lineItemsKey) {
				return false;
			}

			$erpRoot = trim(explode('.', (string) ($c->erp_field ?? ''))[0]);
			if ($erpRoot === $erpLineField && str_ends_with($erpRoot, '_ids')) {
				return false;
			}
		}

		return true;
	});

	$payload = app(FieldMappingService::class)->buildGenericEcomToErpPayload(
		$headerOnly,
		$ecomData,
		$ecomData,
		$this->ecom->driverName(),
		$this->erp->driverName()
	);

	// ── Line item fields → ORM commands ──────────────────────────────
	if ($lineConfigs->isNotEmpty() && $lineItemsKey) {
		$lineItems    = $ecomData[$lineItemsKey] ?? [];
		$lineCommands = [];
		
		

		foreach ($lineItems as $item) {
			$linePayload = $this->buildSingleLinePayload($item, $lineConfigs, $lineItemsKey);
			if (!empty($linePayload)) {
				$lineCommands[] = [0, 0, $linePayload];
			}
		}

		if (!empty($lineCommands)) {
			$payload[$erpLineField] = $lineCommands;
		}
	}

	if ($entityType === 'sales_order' && $lineConfigs->isNotEmpty()) {
		$rawLines = is_array($lineItemsKey ? ($ecomData[$lineItemsKey] ?? null) : null)
			? $ecomData[$lineItemsKey]
			: [];

		if ($rawLines === []) {
			throw new \RuntimeException(
				'Order push aborted: fetched order has no line items (lineItems / line_items empty). '
				. 'Run php artisan cache:clear, then Fetch from Shopify again so line fields are included in the GraphQL query.'
			);
		}

		if (empty($payload[$erpLineField])) {
			throw new \RuntimeException(
				'Order push aborted: line items could not be mapped (missing SKU). '
				. 'Ensure ecom→erp line config maps SKU to the ERP product field, products exist in '
				. $this->settings->erpDisplayName() . ' with matching references, '
				. 'then re-fetch the order from Shopify and retry.'
			);
		}
	}

	if ($entityType === 'sales_order') {
		$payload = $this->enrichSalesOrderErpPayload($payload, $ecomData);
	}

	return $payload;
	}

	/**
	 * Build payload for a single line item.
	 *
	 * Strips the array root prefix from ecom_field before resolving:
	 *   "line_items.price_set.presentment_money.amount"
	 *   → resolves "price_set.presentment_money.amount" on the item object
	 *
	 * Scope=line configs (no prefix) are resolved directly on the item.
	 */
	private function buildSingleLinePayload(
		array $itemData,
		\Illuminate\Support\Collection $lineConfigs,
		?string $lineItemsKey = null
	): array {
		$payload = [];

		foreach ($lineConfigs as $config) {
			$erpField = $this->normalizeErpFieldKey($config);
			if ($erpField === '') {
				continue;
			}

			$mapper = app(FieldMappingService::class);

			if ($config->field_type === 'custom') {
				$value = $config->default_value;
			} elseif ($config->field_type === 'combine') {
				$field1 = $this->stripLineItemsPrefix($config->ecom_field ?? '', $lineItemsKey);
				$field2 = $this->stripLineItemsPrefix($config->ecom_field_2 ?? '', $lineItemsKey);
				$val1 = $this->getNestedValue($itemData, $field1);
				$val2 = $this->getNestedValue($itemData, $field2);
				$value = $mapper->mergeCombinedParts(
					$val1,
					$val2,
					(string) ($config->combine_separator ?? ' '),
					$config->default_value
				);
			} else {
				$ecomField = $this->stripLineItemsPrefix($config->ecom_field ?? '', $lineItemsKey);
				$value     = $mapper->readEcomFieldValue($itemData, $itemData, $ecomField);
				if ($value === null) {
					$value = $config->default_value;
				}
			}

			if ($value !== null && !empty($config->conditions)) {
				$value = app(ValueConditionMapper::class)->apply(
					$value,
					$config->conditions,
					$config->default_value,
				);
			}

			$systemTransform = FieldMappingService::effectiveSystemTransform($config->transform, $config->reverse_transform);
			if ($value !== null && $systemTransform) {
				$value = $this->applyConfigSystemTransform($value, $systemTransform, $itemData);
			}

			if ($systemTransform === 'skip' || $value === null) {
				if ($systemTransform === 'synced_product') {
					$title = trim(strtok(trim((string) ($itemData['title'] ?? '')), "\n"));
					throw new \RuntimeException(
						'Order line product could not be resolved in '
						. $this->settings->erpDisplayName() . '. '
						. 'Post/sync the product first (matching Shopify SKU to the ERP product field), then re-fetch the order. '
						. ($title !== '' ? "Line: {$title}" : '')
					);
				}
				continue;
			}

			$value = $mapper->shapeErpOutput($value, $config);

			$payload[$erpField] = $value;
		}

		return $payload;
	}

	// ── Actual API calls — mapped by entity type ──────────────────────────

	private function createInEcom(string $entityType, array $payload): array
	{
		if ($entityType === 'dispatch') {
			// createFulfillment needs the ecom order ID as a separate argument.
			// PushFulfillmentToEcomJob injects it as _ecom_order_id in the payload.
			$ecomOrderId = (string) ($payload['_ecom_order_id'] ?? '');
			if (!$ecomOrderId) {
				throw new \RuntimeException('dispatch createInEcom: _ecom_order_id not set in payload');
			}
			unset($payload['_ecom_order_id']);
			return $this->ecom->createFulfillment($ecomOrderId, $payload);
		}

		return match ($entityType) {
			'customer'    => $this->ecom->createCustomer($payload),
			'sales_order' => $this->ecom->createOrder($payload),
			default       => $this->ecom->createProduct($payload),
		};
	}

	private function updateInEcom(string $entityType, string $ecomId, array $payload): array
	{
		return match ($entityType) {
			'customer'    => $this->ecom->updateCustomer($ecomId, $payload),
			'sales_order' => (function () use ($ecomId, $payload) {
				$result = $this->ecom->updateOrder($ecomId, $payload);

				return is_array($result) ? $result : ['id' => $ecomId];
			})(),
			'inventory'   => (function () use ($ecomId, $payload) {
				$this->ecom->updateInventory($ecomId, 0, null, $payload);

				return ['id' => $ecomId];
			})(),
			default       => $this->ecom->updateProduct($ecomId, $payload),
		};
	}

	private function createInErp(string $entityType, array $payload, array $sourceContext = []): array
	{
		$id = match ($entityType) {
			'customer'    => $this->erp->createCustomer($payload, $sourceContext),
			'sales_order' => $this->erp->createOrder($payload, $sourceContext),
			'product'     => $this->erp->upsertProduct($payload),
			'inventory'   => (function () use ($payload) {
				$this->erp->updateInventoryLevel($payload);
				return (int) (is_array($payload['product_id'] ?? null)
					? ($payload['product_id'][0] ?? 0)
					: ($payload['product_id'] ?? 0));
			})(),
			default       => 0,
		};

		if (($id === 0 || $id === '0' || $id === '') && !in_array($entityType, ['product'], true)) {
			$known = ['customer', 'sales_order', 'inventory'];
			if (!in_array($entityType, $known, true)) {
				throw new \RuntimeException("createInErp: no handler for entity type '{$entityType}'");
			}
			throw new \RuntimeException(
				"createInErp: {$entityType} create returned no ID — check field config mappings and Odoo payload"
			);
		}

		return ['id' => $id];
	}

	private function updateInErp(string $entityType, int|string $erpId, array $payload, array $sourceContext = []): array
	{
		match ($entityType) {
			'customer'    => $this->erp->updateCustomer($erpId, $payload, $sourceContext),
			'sales_order' => $this->erp->updateOrder($erpId, $payload, $sourceContext),
			'product'     => $this->erp->upsertProduct(array_merge($payload, ['id' => $erpId])),
			'inventory'   => $this->erp->updateInventoryLevel(array_merge($payload, ['product_id' => $erpId])),
			default       => null,
		};

		return ['id' => $erpId];
	}

	// ── System transform helpers (conditions handle value mapping) ────────

	private function applyConfigSystemTransform(mixed $value, string $transform, array $context = []): mixed
	{
		if ($transform === 'synced_customer') {
			return $this->resolveSalesOrderPartnerId($context) ?? $value;
		}

		if ($transform === 'synced_product') {
			return $this->resolveSalesOrderLineProductId($context, $value);
		}

		return app(FieldMappingService::class)->applySystemTransform(
			$value,
			$transform,
			$context,
			app(SettingsService::class)->ecomDriver(),
			'ecom_to_erp',
			$this->erp
		);
	}

	private function normalizeErpFieldKey(\App\Models\ProductFieldConfig $config): string
	{
		$field = trim($config->erp_field ?? '');
		if ($field === '') {
			return '';
		}

		if (preg_match('/^[a-z][a-z0-9_]*$/', $field)) {
			return $field;
		}

		if ($this->erp->driverName() !== 'odoo') {
			return strtolower(str_replace(' ', '_', $field));
		}

		$labelMap = [
			'customer'         => 'partner_id',
			'invoice address'  => 'partner_invoice_id',
			'delivery address' => 'partner_shipping_id',
			'order reference'  => 'client_order_ref',
			'salesperson'      => 'user_id',
			'sales team'       => 'team_id',
		];

		foreach ([strtolower($field), strtolower(trim($config->erp_field_label ?? ''))] as $label) {
			if ($label !== '' && isset($labelMap[$label])) {
				return $labelMap[$label];
			}
		}

		return strtolower(str_replace(' ', '_', $field));
	}

	/** ERP field key for sales-order customer link (field config, then adapter default). */
	private function salesOrderCustomerErpField(string $direction = 'ecom_to_erp'): string
	{
		foreach ($this->getFieldConfigs('sales_order', 'header', $direction) as $config) {
			if (trim($config->transform ?? '') === 'synced_customer') {
				$field = trim(explode('.', (string) ($config->erp_field ?? ''))[0]);
				if ($field !== '') {
					return $field;
				}
			}
		}

		foreach ($this->getFieldConfigs('sales_order', 'header', $direction) as $config) {
			$field = trim(explode('.', (string) ($config->erp_field ?? ''))[0]);
			if ($field !== '' && ($config->field_type ?? '') !== 'custom') {
				return $field;
			}
		}

		return $this->erp->salesOrderCustomerFieldKey($direction);
	}

	private function enrichSalesOrderErpPayload(array $payload, array $ecomData): array
	{
		$customerField = $this->salesOrderCustomerErpField();
		$legacyKeys    = array_values(array_unique([
			'Customer', 'customer', 'partner_id', 'customer_id', $customerField,
		]));

		foreach ($legacyKeys as $legacyKey) {
			if (!isset($payload[$legacyKey])) {
				continue;
			}

			if ($legacyKey === $customerField) {
				continue;
			}

			if (empty($payload[$customerField])) {
				$payload[$customerField] = $payload[$legacyKey];
			}

			unset($payload[$legacyKey]);
		}

		$mappedCustomer = $this->resolvePartnerIdForSalesOrder($ecomData);
		if ($mappedCustomer) {
			$payload[$customerField] = $mappedCustomer;
		} elseif (empty($payload[$customerField])) {
			$email = $this->resolveOrderEmailFromPayload($ecomData);
			if ($email !== '') {
				$payload[$customerField] = $email;
			}
		}

		$adapterDefault = $this->erp->salesOrderCustomerFieldKey();
		if ($customerField !== $adapterDefault && isset($payload[$adapterDefault])) {
			unset($payload[$adapterDefault]);
		}

		return $payload;
	}

	private function salesOrderHasCustomerReference(array $erpPayload): bool
	{
		foreach ([$this->salesOrderCustomerErpField()] as $key) {
			if (!array_key_exists($key, $erpPayload)) {
				continue;
			}

			$value = $erpPayload[$key];
			if ($value === null || $value === '' || $value === false) {
				continue;
			}

			if (is_array($value) && ($value === [] || (($value[0] ?? null) === '' && ($value[1] ?? null) === ''))) {
				continue;
			}

			return true;
		}

		return false;
	}

	private function resolvePartnerIdForSalesOrder(array $ecomOrder): int|string|null
	{
		$resolved = $this->resolveSalesOrderPartnerId($ecomOrder);

		if ($resolved) {
			return $resolved;
		}

		$customer       = $this->orderCustomerFromPayload($ecomOrder);
		$customerEcomId = (string) ($customer['id'] ?? '');

		if ($customerEcomId === '') {
			return null;
		}

		$customerPayload = $this->customerPayloadFromOrder($ecomOrder);
		if ($customerPayload === null) {
			return null;
		}

		$customerSync = app(CustomerSyncService::class);
		if (!$customerSync->isEnabled()) {
			Log::warning('UniversalSyncService: order has Shopify customer but customer sync is disabled', [
				'customer_ecom_id' => $customerEcomId,
			]);

			return null;
		}

		try {
			$erpId = $customerSync->syncCustomerToErp($customerPayload);

			return $erpId
				? $this->normalizePartnerReference($erpId)
				: null;
		} catch (\Throwable $e) {
			Log::warning('UniversalSyncService: customer sync failed while resolving order partner', [
				'customer_ecom_id' => $customerEcomId,
				'error'            => $e->getMessage(),
			]);

			return null;
		}
	}

	/** @param  array<string, mixed>  $ecomOrder */
	private function resolveSalesOrderPartnerId(array $ecomOrder): int|string|null
	{
		$customer       = $this->orderCustomerFromPayload($ecomOrder);
		$customerEcomId = (string) ($customer['id'] ?? '');
		$ecomDriver     = $this->ecom->driverName();

		if ($customerEcomId !== '') {
			$mapping = $this->customerMappingForEcomId($customerEcomId, $ecomDriver);
			if ($mapping?->erp_id) {
				return $this->normalizePartnerReference($mapping->erp_id);
			}
		}

		$mapping = $this->findSyncedCustomerMappingForOrder($ecomOrder);
		if ($mapping?->erp_id) {
			return $this->normalizePartnerReference($mapping->erp_id);
		}

		$email = strtolower($this->resolveOrderEmailFromPayload($ecomOrder));
		if ($email !== '') {
			$found = $this->erp->findCustomerByEmail($email);
			if (is_array($found) && !empty($found['id'])) {
				return $this->normalizePartnerReference($found['id']);
			}

			foreach ($this->syncedCustomerMappings($ecomDriver) as $row) {
				$payload = $row->payload();
				if (!is_array($payload)) {
					continue;
				}

				$mappedEmail = strtolower(trim((string) ($payload['email'] ?? '')));
				if ($mappedEmail !== '' && $mappedEmail === $email) {
					return $this->normalizePartnerReference($row->erp_id);
				}
			}
		}

		$mappings = $this->syncedCustomerMappings($ecomDriver);
		if ($mappings->count() === 1 && $mappings->first()?->erp_id) {
			$sole = $mappings->first();
			Log::info('UniversalSyncService: using sole synced customer for order without PII', [
				'erp_id'  => $sole->erp_id,
				'ecom_id' => $sole->ecom_id,
			]);

			return $this->normalizePartnerReference($sole->erp_id);
		}

		return null;
	}

	/**
	 * Resolve ERPNext Item code for an order line (sku → item_code, with sync-mapping fallbacks).
	 *
	 * @param  array<string, mixed>  $lineItem
	 */
	private function resolveSalesOrderLineProductId(array $lineItem, mixed $skuOrRef): int|string|null
	{
		$mapper     = app(FieldMappingService::class);
		$reference  = trim((string) ($skuOrRef ?? ''));
		$ecomDriver = $this->ecom->driverName();

		if (str_contains($reference, "\n")) {
			$reference = trim(strtok($reference, "\n"));
		}

		if ($reference !== '' && !$this->looksLikeProductTitleNotSku($reference, $lineItem)) {
			$resolved = $this->erp->resolveProductIdByReference($reference);
			if ($resolved !== null && $resolved !== '' && $resolved !== '0') {
				return $resolved;
			}
		}

		foreach ($this->orderLineProductEcomIds($lineItem, $mapper) as $ecomProductId) {
			$erpId = $this->resolveSyncedProductErpId($ecomProductId, $ecomDriver);
			if ($erpId !== null) {
				return $erpId;
			}
		}

		$title = trim((string) ($lineItem['title'] ?? ''));
		if ($title !== '' && preg_match('/\[#(\d+)\]/', $title, $m)) {
			$erpId = $this->resolveSyncedProductErpId($m[1], $ecomDriver);
			if ($erpId !== null) {
				return $erpId;
			}
		}

		return null;
	}

	/** @param  array<string, mixed>  $lineItem
	 * @return list<string>
	 */
	private function orderLineProductEcomIds(array $lineItem, FieldMappingService $mapper): array
	{
		$ids = [];

		foreach (['product.id', 'variant.product.id', 'variant.id', 'product_id'] as $path) {
			$value = $mapper->readEcomFieldValue($lineItem, $lineItem, $path);
			if ($value !== null && $value !== '') {
				$ids[] = (string) $value;
			}
		}

		return array_values(array_unique($ids));
	}

	private function resolveSyncedProductErpId(string $ecomProductId, string $ecomDriver): int|string|null
	{
		if ($ecomProductId === '') {
			return null;
		}

		$mapping = app(\App\Services\MappingService::class)->findByEcomId('product', $ecomProductId, $ecomDriver)
			?? app(\App\Services\MappingService::class)->findByEcomId('product_variant', $ecomProductId, $ecomDriver);

		if ($mapping?->erp_id) {
			return $mapping->erp_id;
		}

		$erpCol = ProductCache::erpIdColumn();
		$cache  = ProductCache::query()
			->where('ecom_product_id', $ecomProductId)
			->whereNotNull($erpCol)
			->where($erpCol, '!=', '')
			->first();

		return $cache?->{$erpCol};
	}

	/** @param  array<string, mixed>  $lineItem */
	private function looksLikeProductTitleNotSku(string $reference, array $lineItem): bool
	{
		$title = trim(strtok(trim((string) ($lineItem['title'] ?? '')), "\n"));

		return $title !== ''
			&& strcasecmp(trim(strtok($reference, "\n")), $title) === 0;
	}

	/** @param  array<string, mixed>  $ecomOrder */
	private function resolveOrderEmailFromPayload(array $ecomOrder): string
	{
		$mapper = app(FieldMappingService::class);

		foreach ($this->salesOrderIdentityFieldPaths($mapper, 'email') as $path) {
			$email = trim((string) $mapper->readEcomFieldValue($ecomOrder, $ecomOrder, $path));
			if ($email !== '') {
				return $email;
			}
		}

		return '';
	}

	/**
	 * @param  array<string, mixed>  $order
	 * @return list<string>
	 */
	private function orderCustomerNameCandidates(array $order): array
	{
		$mapper     = app(FieldMappingService::class);
		$candidates = [];

		foreach ($this->salesOrderIdentityFieldPaths($mapper, 'name') as $path) {
			$value = strtolower(trim((string) $mapper->readEcomFieldValue($order, $order, $path)));
			if ($value === '') {
				continue;
			}

			$candidates[] = $value;

			$parts = preg_split('/\s+/', $value) ?: [];
			if (count($parts) > 1) {
				$candidates[] = $parts[0];
			}
		}

		return array_values(array_unique($candidates));
	}

	/** @return list<string> */
	private function salesOrderIdentityFieldPaths(FieldMappingService $mapper, string $kind): array
	{
		$paths = $mapper->collectConfiguredEcomFields(
			'sales_order',
			$this->ecom->driverName(),
			$this->erp->driverName()
		);

		$selected = [];
		foreach ($paths as $path) {
			$path = trim($path);
			if ($path === '') {
				continue;
			}

			if ($kind === 'email' && ($path === 'email' || str_ends_with(strtolower($path), '.email'))) {
				$selected[] = $path;
			}

			if ($kind === 'name' && preg_match('/\.(firstName|first_name|displayName|name)$/i', $path)) {
				$selected[] = $path;
			}

			if ($kind === 'phone' && preg_match('/\.phone$/i', $path)) {
				$selected[] = $path;
			}
		}

		if ($selected !== []) {
			return array_values(array_unique($selected));
		}

		return match ($kind) {
			'email' => ['email', 'customer.email'],
			'name'  => [
				'billingAddress.firstName',
				'billing_address.first_name',
				'shippingAddress.firstName',
				'customer.firstName',
				'customer.displayName',
			],
			'phone' => ['billingAddress.phone', 'billing_address.phone', 'customer.phone'],
			default => [],
		};
	}

	/** @param  array<string, mixed>  $ecomOrder */
	private function findSyncedCustomerMappingForOrder(array $ecomOrder): ?SyncMapping
	{
		$customer       = $this->orderCustomerFromPayload($ecomOrder);
		$customerEcomId = (string) ($customer['id'] ?? '');
		$ecomDriver     = $this->ecom->driverName();

		if ($customerEcomId !== '') {
			$mapping = $this->customerMappingForEcomId($customerEcomId, $ecomDriver);
			if ($mapping) {
				return $mapping;
			}
		}

		$email = strtolower($this->resolveOrderEmailFromPayload($ecomOrder));
		if ($email !== '') {
			foreach ($this->syncedCustomerMappings($ecomDriver) as $mapping) {
				$payload = $mapping->payload();
				if (!is_array($payload)) {
					continue;
				}

				$mappedEmail = strtolower(trim((string) ($payload['email'] ?? '')));
				if ($mappedEmail !== '' && $mappedEmail === $email) {
					return $mapping;
				}
			}
		}

		$nameCandidates  = $this->orderCustomerNameCandidates($ecomOrder);
		$phoneCandidates = $this->orderPhoneCandidates($ecomOrder);

		if ($nameCandidates === [] && $phoneCandidates === []) {
			return null;
		}

		foreach ($this->syncedCustomerMappings($ecomDriver) as $mapping) {
			if ($this->customerMappingMatchesOrderIdentity($mapping, $nameCandidates, $phoneCandidates)) {
				return $mapping;
			}
		}

		return null;
	}

	/** @return \Illuminate\Support\Collection<int, SyncMapping> */
	private function syncedCustomerMappings(string $ecomDriver)
	{
		return SyncMapping::query()
			->where('entity_type', 'customer')
			->where(function ($q) use ($ecomDriver) {
				$q->where('ecom_driver', $ecomDriver)
					->orWhereNull('ecom_driver');
			})
			->whereNotNull('erp_id')
			->where('erp_id', '!=', '')
			->get();
	}

	private function customerMappingForEcomId(string $customerEcomId, string $ecomDriver): ?SyncMapping
	{
		return SyncMapping::query()
			->where('entity_type', 'customer')
			->where('ecom_id', $customerEcomId)
			->whereNotNull('erp_id')
			->where('erp_id', '!=', '')
			->where(function ($q) use ($ecomDriver) {
				$q->where('ecom_driver', $ecomDriver)
					->orWhereNull('ecom_driver');
			})
			->first();
	}

	/** @param  list<string>  $nameCandidates
	 * @param  list<string>  $phoneCandidates
	 */
	private function customerMappingMatchesOrderIdentity(
		SyncMapping $mapping,
		array $nameCandidates,
		array $phoneCandidates
	): bool {
		$payload = $mapping->payload();
		$payload = is_array($payload) ? $payload : [];

		if ($phoneCandidates !== []) {
			$phones = array_filter([
				preg_replace('/\D+/', '', (string) ($payload['phone'] ?? '')),
				preg_replace('/\D+/', '', (string) ($payload['mobile_no'] ?? '')),
			]);

			foreach ($phoneCandidates as $candidate) {
				if ($candidate !== '' && in_array($candidate, $phones, true)) {
					return true;
				}
			}
		}

		if ($nameCandidates === []) {
			return false;
		}

		$names = array_filter([
			strtolower(trim((string) $mapping->ecom_handle)),
			strtolower(trim((string) ($payload['firstName'] ?? $payload['first_name'] ?? ''))),
			strtolower(trim((string) ($payload['name'] ?? $payload['customer_name'] ?? ''))),
			strtolower(trim((string) $mapping->erp_id)),
		]);

		foreach ($nameCandidates as $candidate) {
			if ($candidate === '') {
				continue;
			}

			foreach ($names as $name) {
				if ($name === '') {
					continue;
				}

				if ($candidate === $name
					|| str_starts_with($name, $candidate . ' ')
					|| str_starts_with($candidate, $name . ' ')
				) {
					return true;
				}
			}
		}

		return false;
	}

	/** @param  array<string, mixed>  $order
	 * @return array<string, mixed>
	 */
	private function orderCustomerFromPayload(array $order): array
	{
		$customer = app(FieldMappingService::class)->readEcomFieldValue($order, $order, 'customer');

		return is_array($customer) ? $customer : [];
	}

	/** @param  array<string, mixed>  $order
	 * @return list<string>
	 */
	private function orderPhoneCandidates(array $order): array
	{
		$mapper = app(FieldMappingService::class);
		$phones = [];

		foreach ($this->salesOrderIdentityFieldPaths($mapper, 'phone') as $path) {
			$raw = preg_replace('/\D+/', '', (string) $mapper->readEcomFieldValue($order, $order, $path));
			if ($raw !== '') {
				$phones[] = $raw;
			}
		}

		return array_values(array_unique($phones));
	}

	private function normalizePartnerReference(mixed $value): int|string|null
	{
		if ($value === null || $value === '' || $value === false) {
			return null;
		}

		if (is_array($value)) {
			$value = $value[0] ?? $value[1] ?? null;
		}

		$reference = ltrim(trim((string) $value), '#');
		if ($reference === '') {
			return null;
		}

		return is_numeric($reference) ? (int) $reference : $reference;
	}

	/** @return array<string, mixed>|null */
	private function customerPayloadFromOrder(array $ecomOrder): ?array
	{
		$customer = is_array($ecomOrder['customer'] ?? null) ? $ecomOrder['customer'] : [];
		$ecomId   = (string) ($customer['id'] ?? '');
		$email    = $this->resolveOrderEmailFromPayload($ecomOrder);

		if ($ecomId === '' && $email === '') {
			return null;
		}

		$billing = is_array($ecomOrder['billing_address'] ?? null)
			? $ecomOrder['billing_address']
			: [];
		$shipping = is_array($ecomOrder['shipping_address'] ?? null)
			? $ecomOrder['shipping_address']
			: [];

		return array_filter([
			'id'        => $ecomId !== '' ? $ecomId : null,
			'email'     => $email !== '' ? $email : null,
			'firstName' => $customer['firstName'] ?? $customer['first_name'] ?? $billing['firstName'] ?? $billing['first_name'] ?? $shipping['firstName'] ?? $shipping['first_name'] ?? null,
			'lastName'  => $customer['lastName'] ?? $customer['last_name'] ?? $billing['lastName'] ?? $billing['last_name'] ?? $shipping['lastName'] ?? $shipping['last_name'] ?? null,
			'phone'     => $customer['phone'] ?? $billing['phone'] ?? $shipping['phone'] ?? $ecomOrder['phone'] ?? null,
			'note'      => $customer['note'] ?? null,
		], fn ($v) => $v !== null && $v !== '');
	}

	private function getNestedValue(array $data, string $key): mixed
	{
		return $this->fields->get($data, $key);
	}

	private function setNestedValue(array &$array, string $path, mixed $value): void
	{
		$this->fields->set($array, $path, $value);
	}

	/**
	 * Store the real Odoo RPC request/response on the sync log (mirrors Shopify wire log).
	 */
	private function finalizeEcomToErpLog(SyncLog $log, string $entityType, int|string|null $erpId, ?array $mappedPayload = null): void
	{
		$wire = method_exists($this->erp, 'takeWireLog') ? $this->erp->takeWireLog() : [];

		if ($wire) {
			$this->attachEcomToErpWireLog($log, $wire, $entityType, $erpId, null, null, $mappedPayload);
			return;
		}

		$writeValues = $mappedPayload ?? [];

		$response = [
			'erp_id' => $erpId,
			'driver' => $this->erp->driverName(),
		];
		if ($entityType === 'product' && $erpId) {
			$response['record'] = $this->readBackErpRecord('product', $erpId);
		}
		if ($entityType === 'sales_order' && $erpId) {
			$response['record'] = $this->readBackErpRecord('sales_order', $erpId);
		}

		$log->update([
			'status'           => SyncLog::STATUS_SUCCESS,
			'request_payload'  => json_encode(
				[
					'driver'         => $this->erp->driverName(),
					'mapped_payload' => $writeValues,
				],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
			'response_payload' => json_encode(
				$response,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
			'synced_at'        => now(),
		]);
	}

	/** @param  array<int, array<string, mixed>>  $wire */
	private function normalizeErpWireEntries(array $wire): array
	{
		return array_values(array_filter(array_map(function (array $w) {
			if (isset($w['url'])) {
				return array_filter([
					'url'      => $w['url'],
					'method'   => $w['method'] ?? null,
					'query'    => $w['query'] ?? null,
					'body'     => $w['body'] ?? null,
					'status'   => $w['status'] ?? null,
					'response' => $w['response'] ?? null,
				], fn ($v) => $v !== null && $v !== []);
			}

			return array_filter([
				'endpoint' => $w['endpoint'] ?? null,
				'model'    => $w['model'] ?? null,
				'method'   => $w['method'] ?? null,
				'args'     => $w['args'] ?? null,
				'kwargs'   => $w['kwargs'] ?? null,
				'result'   => $w['result'] ?? null,
			], fn ($v) => $v !== null && $v !== []);
		}, $wire)));
	}

	private function attachEcomToErpWireLog(
		SyncLog $log,
		array $wire,
		string $entityType,
		int|string|null $erpId = null,
		?string $error = null,
		?string $fullError = null,
		?array $mappedPayload = null,
	): void {
		if ($mappedPayload === null && $log->request_payload) {
			$existing = json_decode($log->request_payload, true);
			if (is_array($existing) && isset($existing['mapped_payload'])) {
				$mappedPayload = $existing['mapped_payload'];
			}
		}

		$apiCalls = $this->normalizeErpWireEntries($wire);

		$requests = array_map(fn ($w) => [
			'endpoint' => $w['endpoint'] ?? $w['url'] ?? null,
			'model'    => $w['model']    ?? null,
			'method'   => $w['method']   ?? null,
			'args'     => $w['args']     ?? null,
			'kwargs'   => $w['kwargs']   ?? [],
			'url'      => $w['url']      ?? null,
			'query'    => $w['query']    ?? null,
			'body'     => $w['body']     ?? null,
		], $wire);

		$calls = array_map(fn ($w) => [
			'model'    => $w['model']    ?? null,
			'method'   => $w['method']   ?? null,
			'result'   => $w['result']   ?? null,
			'url'      => $w['url']      ?? null,
			'status'   => $w['status']   ?? null,
			'response' => $w['response'] ?? null,
		], $wire);

		$response = [
			'erp_id'     => $erpId,
			'driver'     => $this->erp->driverName(),
			'calls'      => $calls,
			'api_calls'  => $apiCalls,
		];

		if ($entityType === 'product' && $erpId) {
			$response['record'] = $this->readBackErpRecord('product', $erpId);
		}

		if ($entityType === 'sales_order' && $erpId) {
			$response['record'] = $this->readBackErpRecord('sales_order', $erpId);
		}

		if ($error) {
			$response['error'] = $error;
		}

		$wirePayload = $this->extractSaleOrderWirePayload($wire);

		$log->update([
			'status'           => $error ? SyncLog::STATUS_FAILED : SyncLog::STATUS_SUCCESS,
			'error_message'    => $error,
			'error_context'    => $fullError ? ['full' => $fullError] : null,
			'attempts'         => ($log->attempts ?? 0) + ($error ? 1 : 0),
			'request_payload'  => json_encode(
				array_filter([
					'driver'         => $this->erp->driverName(),
					'mapped_payload' => $mappedPayload,
					'wire_payload'   => $wirePayload,
					'rpc_calls'      => $requests,
					'api_calls'      => $apiCalls,
				], fn ($v) => $v !== null),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
			'response_payload' => json_encode(
				$response,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
			'synced_at'        => now(),
		]);
	}

	private function readBackErpRecord(string $entityType, int|string $erpId): ?array
	{
		try {
			if ($entityType === 'product' && method_exists($this->erp, 'getProductByIdFull')) {
				$record = $this->erp->getProductByIdFull($erpId);

				return $record ? $this->truncateLargeOdooFields($record) : null;
			}

			if ($entityType === 'sales_order') {
				if (method_exists($this->erp, 'getOrder')) {
					return $this->erp->getOrder($erpId);
				}

				if (method_exists($this->erp, 'getOrderById') && is_numeric($erpId)) {
					return $this->erp->getOrderById((int) $erpId);
				}
			}
		} catch (\Throwable $e) {
			return ['_read_back_error' => $e->getMessage()];
		}

		return null;
	}

	/** @param  array<int, array<string, mixed>>  $wire */
	private function extractSaleOrderWirePayload(array $wire): ?array
	{
		foreach ($wire as $w) {
			if (($w['model'] ?? '') !== 'sale.order') {
				continue;
			}

			$method = $w['method'] ?? '';
			$args   = $w['args'] ?? [];

			if ($method === 'create' && isset($args[0]) && is_array($args[0])) {
				return $args[0];
			}

			if ($method === 'write' && isset($args[1]) && is_array($args[1])) {
				return $args[1];
			}
		}

		return $this->extractErpNextResourceWirePayload($wire, 'Sales Order');
	}

	/**
	 * Actual JSON body.data sent to ERPNext REST (create/update resource).
	 *
	 * @param  array<int, array<string, mixed>>  $wire
	 */
	private function extractErpNextResourceWirePayload(array $wire, string $doctype): ?array
	{
		$encoded = rawurlencode($doctype);

		foreach ($wire as $w) {
			$url    = (string) ($w['url'] ?? '');
			$method = strtoupper((string) ($w['method'] ?? ''));

			if ($url === '' || !str_contains($url, '/api/resource/' . $encoded)) {
				continue;
			}

			if (!in_array($method, ['POST', 'PUT'], true)) {
				continue;
			}

			$body = $w['body'] ?? null;
			if (!is_array($body)) {
				continue;
			}

			$data = $body['data'] ?? null;
			if (is_array($data) && $data !== []) {
				return $data;
			}
		}

		return null;
	}

	private function truncateLargeOdooFields(array $record): array
	{
		foreach (['image_1920', 'image_1024', 'image_512', 'image_256', 'image_128'] as $field) {
			if (!empty($record[$field]) && is_string($record[$field]) && strlen($record[$field]) > 120) {
				$record[$field] = '[base64 ' . strlen($record[$field]) . ' chars truncated]';
			}
		}

		return $record;
	}

	private function markEcomToErpSynced(string $entityType, string $ecomId, array $ecomData): void
	{
		if (!$ecomId) {
			return;
		}

		$updatedAt = $ecomData['updatedAt'] ?? $ecomData['updated_at'] ?? null;

		match ($entityType) {
			'product' => EcomToErpProductState::markSynced($ecomId, $updatedAt),
			'sales_order', 'inventory', 'customer' => SyncEntityState::markSynced(
				$entityType,
				['ecom_id' => $ecomId, 'ecom_driver' => $this->ecom->driverName()],
				$updatedAt
			),
			default => null,
		};
	}

	private function markEcomToErpFailed(string $entityType, string $ecomId, ?string $message = null): void
	{
		if (!$ecomId) {
			return;
		}

		$truncated = $message && strlen($message) > 2000 ? substr($message, 0, 2000) . '…' : $message;

		match ($entityType) {
			'product' => EcomToErpProductState::markFailed($ecomId, $truncated),
			'sales_order', 'inventory', 'customer' => SyncEntityState::markFailed(
				$entityType,
				['ecom_id' => $ecomId, 'ecom_driver' => $this->ecom->driverName()],
				$truncated
			),
			default => null,
		};
	}

	private function markErpToEcomSynced(string $entityType, string $erpId, string $ecomId, ?string $sourceUpdatedAt = null): void
	{
		if (!$erpId) {
			return;
		}

		SyncEntityState::markSynced($entityType, [
			'erp_id'     => $erpId,
			'erp_driver' => $this->erp->driverName(),
		], $sourceUpdatedAt);

		if ($ecomId) {
			SyncMapping::where('entity_type', $entityType)
				->where('erp_id', $erpId)
				->where('erp_driver', $this->erp->driverName())
				->update([
					'ecom_id'             => $ecomId,
					'ecom_driver'         => $this->ecom->driverName(),
					'last_sync_direction' => 'erp_to_ecom',
					'last_synced_at'      => now(),
				]);
		}
	}

	/** @param  array<string, mixed>  $ecomPayload  @param  array<string, mixed>  $result */
	private function persistSalesOrderLogPayload(SyncLog $log, array $ecomPayload, array $result): void
	{
		$payload = ['mapped_payload' => $ecomPayload];

		if (!empty($result['wire_input']) && is_array($result['wire_input'])) {
			$payload['wire_input'] = $result['wire_input'];
		}

		$log->update([
			'request_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
		]);
	}

	/** @param  array<string, mixed>  $mappedPayload */
	private function persistErpToEcomWireLog(SyncLog $log, array $mappedPayload): void
	{
		$wire    = method_exists($this->ecom, 'takeWireLog') ? $this->ecom->takeWireLog() : [];
		$payload = ['mapped_payload' => $mappedPayload];

		if ($wire !== []) {
			$payload['api_calls'] = array_map(fn ($w) => array_filter([
				'action'     => $w['action'] ?? null,
				'query'      => $w['query'] ?? null,
				'variables'  => $w['variables'] ?? null,
				'endpoint'   => $w['endpoint'] ?? 'graphql.json',
				'wire_input' => $w['wire_input'] ?? null,
				'response'   => $w['response'] ?? null,
			], fn ($v) => $v !== null && $v !== []), $wire);
		}

		$log->update([
			'request_payload' => json_encode(
				$payload,
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			),
		]);
	}

	/**
	 * Transform from config row, or inferred from erp_field + ecom_field pairing.
	 */
	private function resolveConfiguredTransform(ProductFieldConfig $config, ?string $resolvedEcomField = null): ?string
	{
		$explicit = FieldMappingService::effectiveSystemTransform(
			$config->transform,
			$config->reverse_transform
		);
		if ($explicit !== null) {
			return $explicit;
		}

		$erp  = trim((string) ($config->erp_field ?? ''));
		$ecom = strtolower(trim((string) ($config->ecom_field ?? '')));
		$leaf = strtolower(trim($resolvedEcomField ?? ''));

		if ($leaf === '' && str_contains($ecom, '.')) {
			$leaf = strtolower((string) array_slice(explode('.', $ecom), -1)[0]);
		}

		if ($erp === 'product_id' && ($leaf === 'id' || str_ends_with($ecom, '.id'))) {
			return 'resolve_fulfillment_line_item_id';
		}

		if ($erp === '_ecom_order_id' && (str_contains($ecom, 'fulfillmentorderid') || $leaf === 'fulfillmentorderid')) {
			return 'resolve_fulfillment_order_id';
		}

		return null;
	}

	/** Keep raw many2one [id, name] when a transform needs the numeric relation id. */
	private function shouldSkipMany2OneCoalesce(?string $transform, string $erpField): bool
	{
		if (in_array($transform, ['resolve_fulfillment_line_item_id', 'resolve_fulfillment_order_id'], true)) {
			return true;
		}

		if (str_starts_with((string) $transform, 'sync_mapping:')) {
			return in_array($erpField, ['product_id', 'partner_id', 'carrier_id'], true);
		}

		return false;
	}

	/** Strip line container prefix from configured ecom paths on line rows. */
	private function stripLineItemsPrefix(string $ecomField, ?string $lineItemsKey): string
	{
		if ($lineItemsKey && str_starts_with($ecomField, $lineItemsKey . '.')) {
			return substr($ecomField, strlen($lineItemsKey) + 1);
		}

		return $ecomField;
	}

	/** Odoo many2one values are often [id, "Label"] — use the label when no transform is configured. */
	private function coalesceMany2OneDisplay(mixed $value, string $erpField): mixed
	{
		if (!is_array($value) || !array_key_exists(1, $value)) {
			return $value;
		}

		if (in_array($erpField, ['partner_id', 'product_id', 'carrier_id', 'location_id', 'currency_id'], true)) {
			return $value[1];
		}

		return $value;
	}

	private function markErpToEcomFailed(string $entityType, string $erpId, ?string $message = null): void
	{
		if (!$erpId) {
			return;
		}

		$truncated = $message && strlen($message) > 2000 ? substr($message, 0, 2000) . '…' : $message;

		SyncEntityState::markFailed($entityType, [
			'erp_id'     => $erpId,
			'erp_driver' => $this->erp->driverName(),
		], $truncated);
	}

	/**
	 * Delete a synced entity from e-commerce, ERP, and local DB (mappings, payloads, logs).
	 *
	 * @return array{message: string, warnings: list<string>}
	 */
	public function deleteEntity(string $entityType, string $localId, string $idSide = 'auto'): array
	{
		$entityType = $entityType === 'sales_order' ? 'order' : $entityType;

		$mapping = $this->resolveDeleteMapping($entityType, $localId, $idSide);

		if (!$mapping && $entityType !== 'product') {
			throw new \RuntimeException('No sync record found for this item.');
		}

		$erpId  = $mapping?->erp_id;
		$ecomId = $mapping?->ecom_id;

		if ($entityType === 'product' && !$mapping) {
			$erpId  = ctype_digit($localId) ? $localId : null;
			$ecomId = !ctype_digit($localId) ? $localId : null;
		}

		$this->deleteEntityRemote($entityType, $erpId, $ecomId);
		$this->deleteEntityLocal($entityType, $mapping, $erpId, $ecomId);

		$label = match ($entityType) {
			'product'   => 'Product',
			'customer'  => 'Customer',
			'inventory' => 'Inventory',
			'dispatch'  => 'Dispatch',
			default     => 'Order',
		};

		$message = "{$label} deleted from {$this->settings->ecomDisplayName()}, "
			. "{$this->settings->erpDisplayName()}, and local database.";

		Log::info("UniversalSyncService: deleted {$entityType} local={$localId} erp={$erpId} ecom={$ecomId}");

		return ['message' => $message, 'warnings' => []];
	}

	private function resolveDeleteMapping(string $entityType, string $localId, string $idSide): ?SyncMapping
	{
		$types = $entityType === 'order' ? ['order', 'sales_order'] : [$entityType];

		$query = SyncMapping::query()->whereIn('entity_type', $types);

		if ($idSide === 'ecom') {
			return $query->where('ecom_id', $localId)->first();
		}

		if ($idSide === 'erp') {
			return $query->where('erp_id', $localId)->first();
		}

		return $query->where(function ($q) use ($localId) {
			$q->where('erp_id', $localId)->orWhere('ecom_id', $localId);
		})->first();
	}

	/** @throws \RuntimeException when a remote delete fails (local DB is not modified) */
	private function deleteEntityRemote(string $entityType, ?string $erpId, ?string $ecomId): void
	{
		if ($ecomId) {
			$this->attemptRemoteDelete(
				fn () => match ($entityType) {
					'product'  => $this->ecom->deleteProduct($ecomId),
					'customer' => $this->ecom->deleteCustomer($ecomId),
					'order'    => $this->ecom->deleteOrder($ecomId),
					default    => null,
				},
				$this->settings->ecomDisplayName(),
				$entityType,
				$ecomId,
			);
		}

		if ($erpId) {
			$normalizedErpId = is_numeric($erpId) ? (int) $erpId : (string) $erpId;
			$this->attemptRemoteDelete(
				fn () => match ($entityType) {
					'product'   => $this->erp->deleteProduct($normalizedErpId),
					'customer'  => $this->erp->deleteCustomer($normalizedErpId),
					'order'     => $this->erp->deleteOrder($normalizedErpId),
					'dispatch'  => $this->erp->deleteDispatch($normalizedErpId),
					'inventory' => null,
					default     => null,
				},
				$this->settings->erpDisplayName(),
				$entityType,
				(string) $erpId,
			);
		}
	}

	private function attemptRemoteDelete(callable $delete, string $platform, string $entityType, string $id): void
	{
		try {
			$delete();
		} catch (\Throwable $e) {
			if ($this->isDeleteBenignError($e)) {
				Log::info("UniversalSyncService: {$platform} delete {$entityType}#{$id} skipped (already removed): " . $e->getMessage());

				return;
			}

			Log::warning("UniversalSyncService: {$platform} delete {$entityType}#{$id}: " . $e->getMessage());

			throw new \RuntimeException(
				$platform . ': ' . (SyncErrorFormatter::short($e) ?? $e->getMessage())
			);
		}
	}

	private function isDeleteBenignError(\Throwable $e): bool
	{
		$msg = strtolower($e->getMessage());

		return str_contains($msg, "can't be found")
			|| str_contains($msg, 'cannot be found')
			|| str_contains($msg, 'not found')
			|| str_contains($msg, 'does not exist')
			|| str_contains($msg, 'has been deleted')
			|| str_contains($msg, 'record does not exist')
			|| str_contains($msg, 'already deleted')
			|| str_contains($msg, 'product does not exist')
			|| str_contains($msg, 'no product found');
	}

	private function deleteEntityLocal(
		string $entityType,
		?SyncMapping $mapping,
		?string $erpId,
		?string $ecomId,
	): void {
		if ($entityType === 'product') {
			$this->deleteProductEntityLocal($mapping, $erpId, $ecomId);
			return;
		}

		if ($entityType === 'order') {
			$this->deleteOrderEntityLocal($mapping, $erpId, $ecomId);
			return;
		}

		if ($mapping) {
			$this->purgeDeleteMappingLogs($mapping);
			$mapping->delete();
		} elseif ($erpId || $ecomId) {
			SyncMapping::where('entity_type', $entityType)
				->where(function ($q) use ($erpId, $ecomId) {
					if ($erpId) {
						$q->where('erp_id', $erpId);
					}
					if ($ecomId) {
						$q->orWhere('ecom_id', $ecomId);
					}
				})
				->get()
				->each(function (SyncMapping $m) {
					$this->purgeDeleteMappingLogs($m);
					$m->delete();
				});
		}
	}

	private function deleteProductEntityLocal(?SyncMapping $mapping, ?string $erpId, ?string $ecomId): void
	{
		$erpCol = ProductCache::erpIdColumn();

		if ($erpId) {
			try {
				app(ProductCacheService::class)->clearCache($erpId);
			} catch (\Throwable $e) {
				Log::debug('UniversalSyncService: product cache clear: ' . $e->getMessage());
			}

			ProductCache::where($erpCol, (string) $erpId)->delete();
		}

		if ($ecomId) {
			ProductCache::where('ecom_product_id', $ecomId)
				->orWhere('ecom_id', $ecomId)
				->delete();

			$path = 'ecom_products/' . SyncPayloadStore::sanitizeId($ecomId) . '.json';
			if (Storage::disk('local')->exists($path)) {
				Storage::disk('local')->delete($path);
			}
		}

		if ($mapping) {
			$this->purgeDeleteMappingLogs($mapping);
			$mapping->delete();
		}

		if ($erpId) {
			SyncMapping::where('entity_type', 'product')->where('erp_id', $erpId)->get()
				->each(function (SyncMapping $m) {
					$this->purgeDeleteMappingLogs($m);
					$m->delete();
				});
			SyncMapping::where('entity_type', 'product_variant')->where('erp_reference', $erpId)->get()
				->each(function (SyncMapping $m) {
					$this->purgeDeleteMappingLogs($m);
					$m->delete();
				});
			SyncMapping::where('entity_type', 'inventory')->where('erp_id', $erpId)->get()
				->each(function (SyncMapping $m) {
					$this->purgeDeleteMappingLogs($m);
					$m->delete();
				});
		}

		if ($ecomId) {
			SyncMapping::where('entity_type', 'product')->where('ecom_id', $ecomId)->get()
				->each(function (SyncMapping $m) {
					$this->purgeDeleteMappingLogs($m);
					$m->delete();
				});
		}
	}

	private function deleteOrderEntityLocal(?SyncMapping $mapping, ?string $erpId, ?string $ecomId): void
	{
		SyncMapping::where('entity_type', 'dispatch')
			->where(function ($q) use ($erpId, $ecomId) {
				if ($erpId) {
					$q->where('erp_reference', $erpId)->orWhere('erp_id', $erpId);
				}
				if ($ecomId) {
					$q->orWhere('ecom_id', $ecomId);
				}
			})
			->get()
			->each(function (SyncMapping $m) {
				$this->purgeDeleteMappingLogs($m);
				$m->delete();
			});

		if ($mapping) {
			$this->purgeDeleteMappingLogs($mapping);
			$mapping->delete();
			return;
		}

		SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
			->where(function ($q) use ($erpId, $ecomId) {
				if ($erpId) {
					$q->where('erp_id', $erpId);
				}
				if ($ecomId) {
					$q->orWhere('ecom_id', $ecomId);
				}
			})
			->get()
			->each(function (SyncMapping $m) {
				$this->purgeDeleteMappingLogs($m);
				$m->delete();
			});
	}

	private function purgeDeleteMappingLogs(SyncMapping $mapping): void
	{
		$ids = array_filter([
			(string) ($mapping->erp_id ?? ''),
			(string) ($mapping->ecom_id ?? ''),
		]);

		if ($ids === []) {
			return;
		}

		SyncLog::where('entity_type', $mapping->entity_type)
			->whereIn('entity_id', $ids)
			->delete();
	}
}