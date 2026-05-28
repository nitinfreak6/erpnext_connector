<?php

namespace App\Services\Sync;

use App\Models\EntityDefinition;
use App\Models\EntityDriverConfig;
use App\Models\ProductFieldConfig;
use App\Models\SyncMapping;
use App\Services\Ecom\EcomInterface;
use App\Services\Erp\ErpInterface;
use App\Services\SettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Universal Sync Service - Entity and Driver Agnostic
 * 
 * This service can sync ANY entity (product, order, customer, etc.)
 * between ANY ecommerce platform and ANY ERP system.
 * 
 * All configuration comes from the database:
 * - entity_definitions: Which entities exist
 * - entity_driver_configs: How each driver handles each entity
 * - product_field_configs: Field mappings per entity/driver pair
 * 
 * No hardcoded logic for specific platforms or entities!
 */
class UniversalSyncService
{
    public function __construct(
        private readonly EcomInterface $ecom,
        private readonly ErpInterface $erp,
        private readonly SettingsService $settings
    ) {}

    /**
     * Sync an entity from ERP to Ecom
     * 
     * @param string $entityType 'product', 'sales_order', 'customer', etc.
     * @param array $erpData Normalized data from ERP
     * @param string|null $scope 'template', 'variant', 'header', 'line', or null for all
     * @return array Created/updated ecom data with ID
     */
    public function syncFromErpToEcom(string $entityType, array $erpData, ?string $scope = null): array
    {
        // 1. Get entity definition
        $entity = EntityDefinition::where('entity_type', $entityType)->firstOrFail();
        
        if (!$entity->is_active) {
            throw new \Exception("Entity {$entityType} is not active");
        }

        // 2. Get driver configs
        $ecomConfig = $entity->getEcomConfig($this->ecom->driverName());
        $erpConfig = $entity->getErpConfig($this->erp->driverName());
        
        if (!$ecomConfig || !$erpConfig) {
            throw new \Exception(
                "Missing driver config for {$entityType}: " .
                "{$this->ecom->driverName()} or {$this->erp->driverName()}"
            );
        }

        // 3. Get field mappings
        $fieldConfigs = $this->getFieldConfigs($entityType, $scope);
        
        if ($fieldConfigs->isEmpty()) {
            Log::warning("No field configs for {$entityType}, scope={$scope}");
            return [];
        }

        // 4. Build ecom payload from ERP data
        $ecomPayload = $this->buildEcomPayload($erpData, $fieldConfigs);

        // 5. Check if entity already exists in ecom
        $mapping = SyncMapping::where('entity_type', $entityType)
            ->where('erp_id', $erpData['id'] ?? $erpData['erp_id'] ?? null)
            ->where('erp_driver', $this->erp->driverName())
            ->first();

        // 6. Create or update in ecom
        if ($mapping && $mapping->ecom_id) {
            // Update existing
            $result = $this->updateInEcom($ecomConfig, $mapping->ecom_id, $ecomPayload);
            
            Log::info("Updated {$entityType} in {$this->ecom->driverName()}", [
                'erp_id' => $erpData['id'],
                'ecom_id' => $mapping->ecom_id,
            ]);
        } else {
            // Create new
            $result = $this->createInEcom($ecomConfig, $ecomPayload);
            
            // Store mapping
            SyncMapping::updateOrCreate(
                [
                    'entity_type' => $entityType,
                    'erp_id' => $erpData['id'] ?? $erpData['erp_id'],
                    'erp_driver' => $this->erp->driverName(),
                ],
                [
                    'ecom_id' => $result['id'] ?? $result['ecom_id'] ?? null,
                    'ecom_driver' => $this->ecom->driverName(),
                    'ecom_secondary_id' => $result['secondary_id'] ?? null,
                ]
            );
            
            Log::info("Created {$entityType} in {$this->ecom->driverName()}", [
                'erp_id' => $erpData['id'],
                'ecom_id' => $result['id'],
            ]);
        }

        return $result;
    }

    /**
     * Sync an entity from Ecom to ERP
     */
    public function syncFromEcomToErp(string $entityType, array $ecomData, ?string $scope = null): array
    {
        $entity = EntityDefinition::where('entity_type', $entityType)->firstOrFail();
        
        $ecomConfig = $entity->getEcomConfig($this->ecom->driverName());
        $erpConfig = $entity->getErpConfig($this->erp->driverName());
        
        $fieldConfigs = $this->getFieldConfigs($entityType, $scope);
        
        // Build ERP payload from ecom data
        $erpPayload = $this->buildErpPayload($ecomData, $fieldConfigs);
        
        // Check if entity exists
        $mapping = SyncMapping::where('entity_type', $entityType)
            ->where('ecom_id', $ecomData['id'] ?? $ecomData['ecom_id'] ?? null)
            ->where('ecom_driver', $this->ecom->driverName())
            ->first();
        
        if ($mapping && $mapping->erp_id) {
            // Update existing
            $result = $this->updateInErp($erpConfig, $mapping->erp_id, $erpPayload);
        } else {
            // Create new
            $result = $this->createInErp($erpConfig, $erpPayload);
            
            // Store mapping
            SyncMapping::updateOrCreate(
                [
                    'entity_type' => $entityType,
                    'ecom_id' => $ecomData['id'] ?? $ecomData['ecom_id'],
                    'ecom_driver' => $this->ecom->driverName(),
                ],
                [
                    'erp_id' => $result['id'] ?? $result['erp_id'] ?? null,
                    'erp_driver' => $this->erp->driverName(),
                ]
            );
        }
        
        return $result;
    }

    /**
     * Get field configurations for entity/driver pair
     */
    private function getFieldConfigs(string $entityType, ?string $scope): \Illuminate\Support\Collection
    {
        $cacheKey = "field_configs_{$entityType}_{$this->ecom->driverName()}_{$this->erp->driverName()}_{$scope}";
        
        return Cache::rememberForever($cacheKey, function () use ($entityType, $scope) {
            $query = ProductFieldConfig::active()
                ->forEntity($entityType)
                ->forDriverPair($this->ecom->driverName(), $this->erp->driverName())
                ->orderBy('sort_order');
            
            if ($scope) {
                $query->where('scope', $scope);
            }
            
            return $query->get();
        });
    }

    /**
     * Build ecommerce payload from ERP data using field configs
     */
    private function buildEcomPayload(array $erpData, \Illuminate\Support\Collection $fieldConfigs): array
    {
        $payload = [];
        
        foreach ($fieldConfigs as $config) {
            // Skip if no ERP field (ecom-only field)
            if (empty($config->erp_field)) {
                continue;
            }
            
            // Get value from ERP data
            $value = $this->getNestedValue($erpData, $config->erp_field);
            
            // Use default if value is null
            if ($value === null && $config->default_value !== null) {
                $value = $config->default_value;
            }
            
            // Apply transformation
            if ($value !== null && $config->transform) {
                $value = $this->applyTransform($value, $config->transform, $erpData);
            }
            
            // Set in payload
            if ($config->ecom_api_path) {
                // Use API path for nested structures (e.g., GraphQL)
                $this->setNestedValue($payload, $config->ecom_api_path, $value);
            } else {
                $payload[$config->ecom_field] = $value;
            }
        }
        
        return $payload;
    }

    /**
     * Build ERP payload from ecommerce data using field configs
     */
    private function buildErpPayload(array $ecomData, \Illuminate\Support\Collection $fieldConfigs): array
    {
        $payload = [];
        
        foreach ($fieldConfigs as $config) {
            if (empty($config->erp_field)) {
                continue;
            }
            
            // Get value from ecom data
            $value = $ecomData[$config->ecom_field] ?? null;
            
            // Apply reverse transformation
            if ($value !== null && $config->reverse_transform) {
                $value = $this->applyReverseTransform($value, $config->reverse_transform);
            }
            
            $payload[$config->erp_field] = $value;
        }
        
        return $payload;
    }

    /**
     * Create entity in ecommerce platform
     */
    private function createInEcom(EntityDriverConfig $config, array $payload): array
    {
        // This method would call the ecom adapter's generic create method
        // For now, we'll use the existing interface methods
        // In future, this can be fully generic
        
        Log::debug("Creating {$config->model_name} in {$config->driver_name}", [
            'payload' => $payload
        ]);
        
        // TODO: Call generic $this->ecom->createEntity($config->model_name, $payload)
        // For now, return mock result
        return ['id' => 'temp_id', 'created' => true];
    }

    /**
     * Update entity in ecommerce platform
     */
    private function updateInEcom(EntityDriverConfig $config, string $id, array $payload): array
    {
        Log::debug("Updating {$config->model_name} #{$id} in {$config->driver_name}", [
            'payload' => $payload
        ]);
        
        // TODO: Call generic $this->ecom->updateEntity($config->model_name, $id, $payload)
        return ['id' => $id, 'updated' => true];
    }

    /**
     * Create entity in ERP system
     */
    private function createInErp(EntityDriverConfig $config, array $payload): array
    {
        Log::debug("Creating {$config->model_name} in {$config->driver_name}", [
            'payload' => $payload
        ]);
        
        // TODO: Call generic $this->erp->createEntity($config->model_name, $payload)
        return ['id' => 'temp_id', 'created' => true];
    }

    /**
     * Update entity in ERP system
     */
    private function updateInErp(EntityDriverConfig $config, int $id, array $payload): array
    {
        Log::debug("Updating {$config->model_name} #{$id} in {$config->driver_name}", [
            'payload' => $payload
        ]);
        
        // TODO: Call generic $this->erp->updateEntity($config->model_name, $id, $payload)
        return ['id' => $id, 'updated' => true];
    }

    /**
     * Apply transformation (ERP → Ecom)
     */
    private function applyTransform($value, string $transform, array $context = []): mixed
    {
        return match($transform) {
            'number_format' => number_format((float)$value, 2, '.', ''),
            'number_format_nullable' => $value == 0 ? null : number_format((float)$value, 2, '.', ''),
            'boolean_to_status' => $value ? 'active' : 'draft',
            'array_second' => is_array($value) && isset($value[1]) ? $value[1] : null,
            'base64_image' => $this->transformBase64Image($value),
            'pass_through' => $value,
            default => $value,
        };
    }

    /**
     * Apply reverse transformation (Ecom → ERP)
     */
    private function applyReverseTransform($value, string $transform): mixed
    {
        return match($transform) {
            'strip_tags' => strip_tags($value),
            'parse_float' => (float)$value,
            'parse_float_nullable' => empty($value) ? null : (float)$value,
            'status_to_boolean' => in_array($value, ['active', 'publish', 'published', true, 1]),
            'pass_through' => $value,
            'skip' => null,
            default => $value,
        };
    }

    private function transformBase64Image(?string $base64): ?array
    {
        if (empty($base64)) return null;
        return [['attachment' => $base64, 'alt' => 'Product Image']];
    }

    /**
     * Get nested value from array using dot notation
     */
    private function getNestedValue(array $data, string $key): mixed
    {
        if (isset($data[$key])) {
            return $data[$key];
        }
        
        $keys = explode('.', $key);
        $value = $data;
        
        foreach ($keys as $k) {
            if (!is_array($value) || !isset($value[$k])) {
                return null;
            }
            $value = $value[$k];
        }
        
        return $value;
    }

    /**
     * Set nested value in array using dot notation
     */
    private function setNestedValue(array &$array, string $path, $value): void
    {
        $keys = explode('.', $path);
        $current = &$array;
        
        foreach ($keys as $key) {
            if (!isset($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        
        $current = $value;
    }
}