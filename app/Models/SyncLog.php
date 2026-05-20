<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SyncLog extends Model
{
    protected $fillable = [
        'direction',
        'entity_type',
        'entity_id',
        'action',
        'status',
        'job_id',
        'request_payload',
        'response_payload',
        'error_message',
        'error_context',
        'attempts',
        'synced_at',
    ];

    protected $casts = [
        'error_context' => 'array',
        'synced_at'     => 'datetime',
    ];

    // ── Generic Direction Constants (Driver-Agnostic) ──────────────────
    const DIRECTION_ERP_TO_ECOM = 'erp_to_ecom';
    const DIRECTION_ECOM_TO_ERP = 'ecom_to_erp';

    // ── Legacy Constants (Backward Compatibility) ───────────────────────
    // These map to generic directions for gradual migration
    const DIRECTION_ODOO_TO_SHOPIFY  = 'erp_to_ecom';  // ← Now returns generic
    const DIRECTION_SHOPIFY_TO_ODOO  = 'ecom_to_erp';  // ← Now returns generic

    const STATUS_PENDING    = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_SUCCESS    = 'success';
    const STATUS_FAILED     = 'failed';
    const STATUS_SKIPPED    = 'skipped';

    public function markSuccess(string $response = ''): void
    {
        $this->update([
            'status'           => self::STATUS_SUCCESS,
            'response_payload' => $response,
            'synced_at'        => now(),
        ]);
    }

    public function markFailed(string $error, array $context = []): void
    {
        $this->update([
            'status'        => self::STATUS_FAILED,
            'error_message' => $error,
            'error_context' => $context,
            'attempts'      => $this->attempts + 1,
        ]);
    }

    public function markSkipped(string $reason, array $context = []): void
    {
        $this->update([
            'status'        => self::STATUS_SKIPPED,
            'error_message' => $reason,
            'error_context' => $context,
        ]);
    }
}