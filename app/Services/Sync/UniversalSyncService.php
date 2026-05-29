<?php

namespace App\Services\Sync;

use App\Models\EntityDefinition;
use App\Models\ProductFieldConfig;
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
 * Adding a new entity: add rows in product_field_configs for that entity_type.
 * Adding a new driver: implement ErpInterface or EcomInterface.
 */
class UniversalSyncService
{
    public function __construct(
        private readonly EcomInterface $ecom,
        private readonly ErpInterface  $erp,
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

        $mapping = SyncMapping::where('entity_type', $entityType)
            ->where('erp_id', $erpId)
            ->where('erp_driver', $this->erp->driverName())
            ->first();

        if ($mapping && $mapping->ecom_id) {
            $result  = $this->updateInEcom($entityType, $mapping->ecom_id, $ecomPayload);
            $ecomId  = $mapping->ecom_id;
            Log::info("UniversalSyncService: updated {$entityType} #{$erpId} → {$this->ecom->driverName()} #{$ecomId}");
        } else {
            $result = $this->createInEcom($entityType, $ecomPayload);
            $ecomId = (string) ($result['id'] ?? '');

            if ($ecomId && $erpId) {
                SyncMapping::updateOrCreate(
                    ['entity_type' => $entityType, 'erp_id' => $erpId, 'erp_driver' => $this->erp->driverName()],
                    ['ecom_id' => $ecomId, 'ecom_driver' => $this->ecom->driverName(), 'last_synced_at' => now(), 'last_sync_direction' => 'erp_to_ecom']
                );
            }

            Log::info("UniversalSyncService: created {$entityType} #{$erpId} → {$this->ecom->driverName()} #{$ecomId}");
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

        $fieldConfigs = $this->getFieldConfigs($entityType, $scope);

        if ($fieldConfigs->isEmpty()) {
            Log::warning("UniversalSyncService: No field configs for {$entityType}, scope={$scope}");
            return [];
        }

        $erpPayload = $this->buildErpPayload($ecomData, $fieldConfigs);
        $ecomId     = (string) ($ecomData['id'] ?? '');

        $mapping = SyncMapping::where('entity_type', $entityType)
            ->where('ecom_id', $ecomId)
            ->where('ecom_driver', $this->ecom->driverName())
            ->first();

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

        return array_merge($result, ['id' => $erpId, 'erp_id' => $erpId]);
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

    private function buildErpPayload(array $ecomData, \Illuminate\Support\Collection $fieldConfigs): array
    {
        $payload = [];

        foreach ($fieldConfigs as $config) {
            if (empty($config->erp_field)) continue;

            $value = $this->getNestedValue($ecomData, $config->ecom_field ?? '');

            if ($value === null) $value = $config->default_value;

            $payload[$config->erp_field] = $value;
        }

        return $payload;
    }

    // ── Actual API calls — mapped by entity type ──────────────────────────

    /**
     * Routes ecom CREATE call to the correct EcomInterface method by entity type.
     * No hardcoded field names — payload is already built from field configs.
     */
    private function createInEcom(string $entityType, array $payload): array
    {
        return match ($entityType) {
            'customer'    => $this->ecom->createCustomer($payload),
            'sales_order' => $this->ecom->createOrder($payload),
            default       => $this->ecom->createProduct($payload),
        };
    }

    /**
     * Routes ecom UPDATE call to the correct EcomInterface method by entity type.
     */
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

    /**
     * Routes ERP CREATE call to the correct ErpInterface method by entity type.
     */
    private function createInErp(string $entityType, array $payload): array
    {
        $id = match ($entityType) {
            'customer'    => $this->erp->createCustomer($payload),
            'sales_order' => $this->erp->createOrder($payload),
            default       => 0,
        };

        return ['id' => $id];
    }

    /**
     * Routes ERP UPDATE call to the correct ErpInterface method by entity type.
     */
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
            'number_format'          => number_format((float) $value, 2, '.', ''),
            'number_format_nullable' => $value == 0 ? null : number_format((float) $value, 2, '.', ''),
            'boolean_status'         => $value ? 'active' : 'draft',
            'boolean_to_status'      => $value ? 'active' : 'draft',
            'array_second'           => is_array($value) ? ($value[1] ?? null) : $value,
            'base64_image'           => !empty($value) ? [['attachment' => $value]] : null,
            'strip_tags'             => strip_tags((string) $value),
            'parse_float'            => (float) $value,
            'status_to_boolean'      => in_array($value, ['active', 'publish', 'published', true, 1]),
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
