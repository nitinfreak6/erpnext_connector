<?php

namespace App\Services\Sync;

use App\Models\EntityDefinition;
use App\Models\ProductFieldConfig;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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
 * If no line_container row exists, "order_line" is used as default.
 *
 * ── READONLY FIELDS ───────────────────────────────────────────────────────
 *
 * Mark is_readonly = true on fields computed by the ERP (e.g. amount_total,
 * amount_untaxed in Odoo). They are skipped when building the ERP payload.
 */
class UniversalSyncService
{
    public function __construct(
        private readonly EcomInterface   $ecom,
        private readonly ErpInterface    $erp,
        private readonly SettingsService $settings
    ) {}

    // ── ERP → Ecom ────────────────────────────────────────────────────────

    public function syncFromErpToEcom(string $entityType, array $erpData, ?string $scope = null): array
    {
        $entity = EntityDefinition::where('entity_type', $entityType)->firstOrFail();

        if (!$entity->is_active) {
            throw new \RuntimeException("Entity [{$entityType}] is not active.");
        }

        $fieldConfigs = $this->getFieldConfigs($entityType, $scope);

        if ($fieldConfigs->isEmpty()) {
            Log::warning("UniversalSyncService: No field configs for {$entityType}, scope={$scope}");
            return [];
        }

        $ecomPayload = $this->buildEcomPayload($erpData, $fieldConfigs);
        $erpId       = (string) ($erpData['id'] ?? '');

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
            'request_payload' => json_encode($ecomPayload),
        ]);

        try {
            if ($mapping && $mapping->ecom_id) {
                $result = $this->updateInEcom($entityType, $mapping->ecom_id, $ecomPayload);
                $ecomId = $mapping->ecom_id;
                Log::info("UniversalSyncService: updated {$entityType} #{$erpId} → {$this->ecom->driverName()} #{$ecomId}");
            } else {
                $result = $this->createInEcom($entityType, $ecomPayload);
                $ecomId = (string) ($result['id'] ?? '');

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

            $log->markSuccess(json_encode(['ecom_id' => $ecomId]));
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
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

        $erpPayload = $this->buildErpPayloadFull($entityType, $ecomData, $scope ?? 'header');
        $ecomId     = (string) ($ecomData['id'] ?? '');

        $mapping = SyncMapping::where('entity_type', $entityType)
            ->where('ecom_id', $ecomId)
            ->where('ecom_driver', $this->ecom->driverName())
            ->first();

        $log = SyncLog::create([
            'direction'       => SyncLog::DIRECTION_ECOM_TO_ERP,
            'entity_type'     => $entityType,
            'entity_id'       => $ecomId,
            'action'          => ($mapping && $mapping->erp_id) ? 'update' : 'create',
            'status'          => SyncLog::STATUS_PROCESSING,
            'request_payload' => json_encode($erpPayload),
        ]);

        try {
            if ($mapping && $mapping->erp_id) {
                $result = $this->updateInErp($entityType, (int) $mapping->erp_id, $erpPayload);
                $erpId  = $mapping->erp_id;
                Log::info("UniversalSyncService: updated {$entityType} ecom#{$ecomId} → {$this->erp->driverName()} #{$erpId}");
            } else {
                $result = $this->createInErp($entityType, $erpPayload);
                $erpId  = (string) ($result['id'] ?? '');

                if ($erpId && $ecomId) {
                    SyncMapping::updateOrCreate(
                        ['entity_type' => $entityType, 'ecom_id' => $ecomId, 'ecom_driver' => $this->ecom->driverName()],
                        ['erp_id' => $erpId, 'erp_driver' => $this->erp->driverName(), 'last_synced_at' => now(), 'last_sync_direction' => 'ecom_to_erp']
                    );
                }

                Log::info("UniversalSyncService: created {$entityType} ecom#{$ecomId} → {$this->erp->driverName()} #{$erpId}");
            }

            $log->markSuccess(json_encode(['erp_id' => $erpId]));
        } catch (\Throwable $e) {
            $log->markFailed($e->getMessage());
            throw $e;
        }

        return array_merge($result, ['id' => $erpId, 'erp_id' => $erpId]);
    }

    // ── Public payload builder ────────────────────────────────────────────

    public function buildErpPayloadOnly(string $entityType, array $ecomData, string $scope = 'header'): array
    {
        return $this->buildErpPayloadFull($entityType, $ecomData, $scope);
    }

    // ── Public helper: ERP fields needed to satisfy configs for an entity ──
    // Use this to build the field list for ERP API calls (e.g. stock.move read)
    // instead of hardcoding field names in adapters.
    public function getErpFieldsToFetch(string $entityType, ?string $scope = null): array
    {
        $configs = $this->getFieldConfigs($entityType, $scope);

        $fields = $configs
            ->flatMap(fn($c) => array_filter([
                $c->erp_field   ? explode('.', $c->erp_field)[0]   : null,
                $c->erp_field_2 ? explode('.', $c->erp_field_2)[0] : null,
            ]))
            ->unique()
            ->filter()
            ->values()
            ->toArray();

        // Always include id — needed for mapping lookups
        if (!in_array('id', $fields)) {
            array_unshift($fields, 'id');
        }

        return $fields;
    }

    // ── Field config loader ───────────────────────────────────────────────

    private function getFieldConfigs(string $entityType, ?string $scope): \Illuminate\Support\Collection
    {
        $ecomDriver = $this->ecom->driverName();
        $erpDriver  = $this->erp->driverName();
        $cacheKey   = "field_configs_{$entityType}_{$ecomDriver}_{$erpDriver}_{$scope}";

        return Cache::remember($cacheKey, 300, function () use ($entityType, $scope, $ecomDriver, $erpDriver) {
            $query = ProductFieldConfig::where('entity_type', $entityType)
                ->where('ecom_driver', $ecomDriver)
                ->where('erp_driver', $erpDriver)
                ->where('is_active', true)
                ->orderBy('sort_order');

            if ($scope) {
                $query->where('scope', $scope);
            }

            return $query->get();
        });
    }

    // ── Payload builders ──────────────────────────────────────────────────

    /**
     * ERP → Ecom payload builder.
     */
    private function buildEcomPayload(array $erpData, \Illuminate\Support\Collection $fieldConfigs): array
    {
        $payload = [];

        foreach ($fieldConfigs as $config) {
            if ($config->field_type === 'custom') {
                $value = $config->default_value;
            } elseif ($config->field_type === 'combine') {
                $val1  = $this->getNestedValue($erpData, $config->erp_field  ?? '');
                $val2  = $this->getNestedValue($erpData, $config->erp_field_2 ?? '');
                $sep   = $config->combine_separator ?? ' ';
                $value = trim(($val1 ?? '') . ($val1 && $val2 ? $sep : '') . ($val2 ?? ''));
                if (empty($value)) $value = $config->default_value;
            } else {
                $value = $this->getNestedValue($erpData, $config->erp_field ?? '');
                if ($value === null) $value = $config->default_value;
            }

            if ($value !== null && $config->transform) {
                $value = $this->applyTransform($value, $config->transform, $erpData);
            }

            if ($value !== null) {
                $payload[$config->ecom_field] = $value;
            }
        }

        return $payload;
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
    $headerConfigs    = $this->getFieldConfigs($entityType, 'header');
    $lineConfigs      = $this->getFieldConfigs($entityType, 'line');

    $lineItemsKey = null;
    $erpLineField = 'order_line';

    // ── Find line_container row from header configs ────────────────────
    foreach ($headerConfigs as $config) {
        if ($config->transform === 'line_container') {
            $erpLineField = $config->erp_field ?: 'order_line';
            break;
        }
    }

    // ── Detect line items array key from first line-scope config ──────
    // e.g. "line_items.price_set.amount" → root = "line_items"
    foreach ($lineConfigs as $config) {
        $root = explode('.', $config->ecom_field ?? '')[0];
        if (isset($ecomData[$root]) && is_array($ecomData[$root])) {
            $lineItemsKey = $root;
            break;
        }
    }

    $payload = [];

    // ── Header fields ─────────────────────────────────────────────────
    foreach ($headerConfigs as $config) {
        if (!empty($config->is_readonly))      continue;
        if ($config->transform === 'line_container') continue;
        if (empty($config->erp_field))         continue;

        $value = $this->getNestedValue($ecomData, $config->ecom_field ?? '');
        if ($value === null) $value = $config->default_value;

        if ($value !== null && $config->transform) {
            $value = $this->applyTransform($value, $config->transform, $ecomData);
        }

        if ($value !== null) {
            $payload[$config->erp_field] = $value;
        }
    }

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
            if (!empty($config->is_readonly)) continue;
            if (empty($config->erp_field))   continue;

            $ecomField = $config->ecom_field ?? '';

            // Strip root prefix: "line_items.price_set.amount" → "price_set.amount"
            if ($lineItemsKey && str_starts_with($ecomField, $lineItemsKey . '.')) {
                $ecomField = substr($ecomField, strlen($lineItemsKey) + 1);
            }

            $value = $this->getNestedValue($itemData, $ecomField);
            if ($value === null) $value = $config->default_value;

            if ($value !== null && $config->transform) {
                $value = $this->applyTransform($value, $config->transform, $itemData);
            }

            if ($value !== null) {
                $payload[$config->erp_field] = $value;
            }
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
                $this->ecom->updateOrder($ecomId, $payload);
                return ['id' => $ecomId];
            })(),
            'inventory'   => (function () use ($ecomId, $payload) {
                $qty = (int) ($payload['qty_available'] ?? $payload['quantity'] ?? 0);
                $this->ecom->updateInventory($ecomId, $qty);
                return ['id' => $ecomId];
            })(),
            default       => $this->ecom->updateProduct($ecomId, $payload),
        };
    }

    private function createInErp(string $entityType, array $payload): array
    {
        $id = match ($entityType) {
            'customer'    => $this->erp->createCustomer($payload),
            'sales_order' => $this->erp->createOrder($payload),
            default       => 0,
        };

        return ['id' => $id];
    }

    private function updateInErp(string $entityType, int $erpId, array $payload): array
    {
        match ($entityType) {
            'customer' => $this->erp->updateCustomer($erpId, $payload),
            default    => null,
        };

        return ['id' => $erpId];
    }

    // ── Transform helpers ─────────────────────────────────────────────────

    private function applyTransform(mixed $value, string $transform, array $context = []): mixed
    {
        return match ($transform) {
            'number_format' => (float) number_format((float) $value, 2, '.', ''),
			'parse_int' => (int) $value,
            'number_format_nullable' => $value == 0 ? null : number_format((float) $value, 2, '.', ''),
            'boolean_status'         => $value ? 'active' : 'draft',
            'boolean_to_status'      => $value ? 'active' : 'draft',
            'array_second'           => is_array($value) ? ($value[1] ?? null) : $value,
            'base64_image'           => !empty($value) ? [['attachment' => $value]] : null,
            'strip_tags'             => strip_tags((string) $value),
            'parse_float'            => (float) $value,
            'status_to_boolean'      => in_array($value, ['active', 'publish', 'published', true, 1]),
            'line_container'         => $value,
            default                  => $value,
        };
    }

    private function getNestedValue(array $data, string $key): mixed
    {
        if ($key === '') return null;
        if (isset($data[$key])) return $data[$key];

        $parts = explode('.', $key);
        $value = $data;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) return null;
            $value = $value[$part];
        }
        return $value;
    }

    private function setNestedValue(array &$array, string $path, mixed $value): void
    {
        $keys    = explode('.', $path);
        $current = &$array;
        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        $current = $value;
    }
}