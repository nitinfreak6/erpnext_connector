<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EntityDriverConfig extends Model
{
    protected $fillable = [
        'entity_type',
        'driver_type',
        'driver_name',
        'model_name',
        'api_endpoint',
        'list_method',
        'create_method',
        'update_method',
        'meta',
        'is_active',
    ];

    protected $casts = [
        'meta' => 'array',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function entityDefinition()
    {
        return $this->belongsTo(EntityDefinition::class, 'entity_type', 'entity_type');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForDriver($query, string $driverType, string $driverName)
    {
        return $query->where('driver_type', $driverType)
                    ->where('driver_name', $driverName);
    }

    public function scopeForEntity($query, string $entityType)
    {
        return $query->where('entity_type', $entityType);
    }
}