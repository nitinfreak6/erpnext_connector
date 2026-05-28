<?php

namespace App\Services;

use App\Models\ProductFieldConfig;
use Illuminate\Support\Facades\Cache;

/**
 * Field Mapping Service - Driver-Agnostic
 * 
 * Handles field mappings between any ERP ↔ Ecom driver pair.
 * 
 * Example usage:
 * 
 * // Get mappings for Shopify ↔ Odoo
 * $mappings = $service->getProductMappings('shopify', 'odoo');
 * 
 * // Get mappings for WooCommerce ↔ NetSuite
 * $mappings = $service->getProductMappings('woocommerce', 'netsuite');
 * 
 * // Transform value using mapping
 * $transformed = $service->transform($value, 'number_format');
 */
class FieldMappingService
{
    /**
     * Get field mappings for a specific driver pair
     * 
     * @param string $entityType 'product', 'order', 'customer', 'inventory'
     * @param string $ecomDriver 'shopify', 'woocommerce', 'magento'
     * @param string $erpDriver 'odoo', 'netsuite', 'sap'
     * @param string|null $scope 'template', 'variant', or null for all
     * @return \Illuminate\Support\Collection
     */
    public function getMappings(
        string $entityType,
        string $ecomDriver,
        string $erpDriver,
        ?string $scope = null
    ): \Illuminate\Support\Collection {
        $cacheKey = ProductFieldConfig::cacheKey($entityType, $ecomDriver, $erpDriver);
        
        if ($scope) {
            $cacheKey .= "_{$scope}";
        }
        
        return Cache::rememberForever($cacheKey, function () use ($entityType, $ecomDriver, $erpDriver, $scope) {
            $query = ProductFieldConfig::active()
                ->forEntity($entityType)
                ->forDriverPair($ecomDriver, $erpDriver)
                ->orderBy('sort_order');
            
            if ($scope) {
                $query->where('scope', $scope);
            }
            
            return $query->get();
        });
    }

    /**
     * Get template-level mappings (for products, customers)
     */
    public function getTemplateMappings(string $entityType, string $ecomDriver, string $erpDriver): \Illuminate\Support\Collection
    {
        return $this->getMappings($entityType, $ecomDriver, $erpDriver, 'template');
    }

    /**
     * Get variant-level mappings (for product variants)
     */
    public function getVariantMappings(string $ecomDriver, string $erpDriver): \Illuminate\Support\Collection
    {
        return $this->getMappings('product', $ecomDriver, $erpDriver, 'variant');
    }

    /**
     * Build ecommerce payload from ERP data
     * 
     * @param array $erpData Normalized ERP data
     * @param string $ecomDriver Target ecommerce platform
     * @param string $erpDriver Source ERP system
     * @param string $scope 'template' or 'variant'
     * @return array Ecommerce-ready payload
     */
    public function buildEcomPayload(
        array $erpData,
        string $ecomDriver,
        string $erpDriver,
        string $scope = 'template'
    ): array {
        $mappings = $this->getMappings('product', $ecomDriver, $erpDriver, $scope);
        $payload = [];
        
        foreach ($mappings as $mapping) {
            $erpField = $mapping->erp_field;
            $ecomField = $mapping->ecom_field;
            
            // Skip if no ERP field specified (ecom-only field)
            if (empty($erpField)) {
                continue;
            }
            
            // Get value from ERP data
            $value = $erpData[$erpField] ?? $mapping->default_value;
            
            // Apply transformation if specified
            if ($mapping->transform && $value !== null) {
                $value = $this->transform($value, $mapping->transform);
            }
            
            // Handle API path for nested structures (e.g. GraphQL)
            if ($mapping->ecom_api_path) {
                $this->setNestedValue($payload, $mapping->ecom_api_path, $value);
            } else {
                $payload[$ecomField] = $value;
            }
        }
        
        return $payload;
    }

    /**
     * Extract ERP data from ecommerce payload
     * 
     * @param array $ecomData Ecommerce platform data
     * @param string $ecomDriver Source ecommerce platform
     * @param string $erpDriver Target ERP system
     * @param string $scope 'template' or 'variant'
     * @return array ERP-ready data
     */
    public function extractErpData(
        array $ecomData,
        string $ecomDriver,
        string $erpDriver,
        string $scope = 'template'
    ): array {
        $mappings = $this->getMappings('product', $ecomDriver, $erpDriver, $scope);
        $erpData = [];
        
        foreach ($mappings as $mapping) {
            $ecomField = $mapping->ecom_field;
            $erpField = $mapping->erp_field;
            
            // Skip if no ERP field (ecom-only field)
            if (empty($erpField)) {
                continue;
            }
            
            // Get value from ecom data
            $value = $ecomData[$ecomField] ?? null;
            
            // Apply reverse transformation if specified
            if ($mapping->reverse_transform && $value !== null) {
                $value = $this->reverseTransform($value, $mapping->reverse_transform);
            }
            
            $erpData[$erpField] = $value;
        }
        
        return $erpData;
    }

    /**
     * Apply transformation to value (ERP → Ecom)
     */
    public function transform($value, string $transformType)
    {
        return match ($transformType) {
            'number_format' => number_format((float) $value, 2, '.', ''),
            'number_format_nullable' => $value == 0 ? null : number_format((float) $value, 2, '.', ''),
            'boolean_status' => $value ? 'active' : 'draft',
            'array_second' => is_array($value) && isset($value[1]) ? $value[1] : null,
            'base64_image' => $this->transformBase64Image($value),
            'pass_through' => $value,
            default => $value,
        };
    }

    /**
     * Apply reverse transformation (Ecom → ERP)
     */
    public function reverseTransform($value, string $transformType)
    {
        return match ($transformType) {
            'strip_tags' => strip_tags($value),
            'parse_float' => (float) $value,
            'parse_float_nullable' => empty($value) ? null : (float) $value,
            'status_to_boolean' => in_array($value, ['active', 'publish', 'published', true, 1]),
            'pass_through' => $value,
            'skip' => null,
            default => $value,
        };
    }

    /**
     * Transform base64 image to ecommerce image array
     */
    private function transformBase64Image(?string $base64): ?array
    {
        if (empty($base64)) {
            return null;
        }
        
        return [
            [
                'attachment' => $base64,
                'alt' => 'Product Image',
            ]
        ];
    }

    /**
     * Set nested value in array using dot notation
     * Example: 'productCreate.product.title' → $array['productCreate']['product']['title'] = $value
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

    /**
     * Check if a driver pair has any mappings configured
     */
    public function hasMappings(string $entityType, string $ecomDriver, string $erpDriver): bool
    {
        return $this->getMappings($entityType, $ecomDriver, $erpDriver)->isNotEmpty();
    }

    /**
     * Clear all cached mappings
     */
    public function clearCache(): void
    {
        Cache::flush(); // Or use specific cache tags if available
    }
}