<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductFieldConfig extends Model
{
	// Note: This model is named ProductFieldConfig for backwards compatibility
	// but actually handles ALL entity types (products, orders, customers, etc.)
	// Consider renaming to FieldConfig in future
	
	protected $table = 'product_field_configs';

	protected $fillable = [
		'entity_type',          // product, sales_order, customer, inventory, dispatch
		'direction',            // erp_to_ecom | ecom_to_erp
		'ecom_driver',          // shopify, woocommerce, magento
		'ecom_field',           // title, regular_price, order_number
		'ecom_field_label',
		'ecom_field_2',         // second source field for combine (ecom→erp)
		'ecom_field_2_label',
		'ecom_api_path',        // GraphQL: productCreate.product.title, REST: name
		'ecom_cast',            // GraphQL: String!, REST: null
		'field_type',           // default, custom, combine
		'erp_driver',           // odoo, netsuite, sap
		'erp_field',            // name, list_price, so_number
		'erp_field_label',
		'scope',                // template, variant, header, line, default
		'default_value',
		'transform',            // System transform from FieldTransformRegistry (editable in UI)
		'conditions',           // Value map: source:target (e.g. 1:ACTIVE, 0:DRAFT)
		'reverse_transform',    // Legacy — migrated to conditions
		'min_length',
		'max_length',
		'is_active',
		'sort_order',
		'is_item_level',
		'is_readonly',
		
		// DEPRECATED (backwards compatibility)
		'odoo_field',
		'odoo_field_label',
		'odoo_field_2',
		'combine_separator',
	];

	protected $casts = [
		'is_active'  => 'boolean',
		'min_length' => 'integer',
		'max_length' => 'integer',
		'sort_order' => 'integer',
		'is_item_level' => 'boolean',
		'is_readonly' => 'boolean',
	];

	public function scopeActive($query)
	{
		return $query->where('is_active', true);
	}

	public function scopeForDriverPair($query, string $ecomDriver, string $erpDriver)
	{
		return $query->where('ecom_driver', $ecomDriver)
					->where('erp_driver', $erpDriver);
	}

	public function scopeForEntity($query, string $entityType)
	{
		return $query->where('entity_type', $entityType);
	}

	public function scopeTemplateLevel($query)
	{
		return $query->where('scope', 'template');
	}

	public function scopeVariantLevel($query)
	{
		return $query->where('scope', 'variant');
	}

	/** Rows with sort_order >= 1 first (asc), then unset/0 rows by id. */
	public function scopeOrdered($query)
	{
		return $query
			->orderByRaw('CASE WHEN sort_order IS NULL OR sort_order < 1 THEN 999999 ELSE sort_order END')
			->orderBy('id');
	}
	
	// Relationships
	public function entityDefinition()
	{
		return $this->belongsTo(EntityDefinition::class, 'entity_type', 'entity_type');
	}

	// ── Auto-clear payload cache on ANY change ───────────────────────────
	// This fires on save/update/delete regardless of which controller triggered it.

	protected static function booted(): void
	{
		$clear = function () {
			// Clear all driver pair caches
			\Illuminate\Support\Facades\Cache::flush(); // Or use tags if available
			
			// Legacy cache key
			\Illuminate\Support\Facades\Cache::forget('product_field_configs_shopify');
		};

		static::saved($clear);    // create + update
		static::deleted($clear);  // delete
	}
	
	/**
	 * Get cache key for specific driver pair
	 */
	public static function cacheKey(string $entityType, string $ecomDriver, string $erpDriver): string
	{
		return "field_configs_{$entityType}_{$ecomDriver}_{$erpDriver}";
	}
}