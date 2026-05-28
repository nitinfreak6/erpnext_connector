<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntityDefinition extends Model
{
    protected $fillable = [
        'entity_type',
        'label',
        'scopes',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'scopes' => 'array',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // Relationships
    public function driverConfigs()
    {
        return $this->hasMany(EntityDriverConfig::class, 'entity_type', 'entity_type');
    }

    public function fieldConfigs()
    {
        return $this->hasMany(FieldConfig::class, 'entity_type', 'entity_type');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    // Helpers
    public function getEcomConfig(string $driverName): ?EntityDriverConfig
    {
        return $this->driverConfigs()
            ->where('driver_type', 'ecom')
            ->where('driver_name', $driverName)
            ->first();
    }

    public function getErpConfig(string $driverName): ?EntityDriverConfig
    {
        return $this->driverConfigs()
            ->where('driver_type', 'erp')
            ->where('driver_name', $driverName)
            ->first();
    }

    public static function getActive(): \Illuminate\Support\Collection
    {
        return static::active()->orderBy('sort_order')->get();
    }
}