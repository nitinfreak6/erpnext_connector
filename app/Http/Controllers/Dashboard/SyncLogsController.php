<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Models\SyncLog;
use App\Services\SettingsService;
use Illuminate\Http\Request;

class SyncLogsController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request)
    {
        $direction  = $request->input('direction');
        $entityType = $request->input('entity_type');
        $status     = $request->input('status');
        $search     = $request->input('search');
        $dateFrom   = $request->input('date_from');
        $dateTo     = $request->input('date_to');
        $perPage    = (int) $request->input('per_page', 60);

        $query = SyncLog::orderByDesc('created_at');

        // Only filter by direction when user explicitly selects one
        // Default shows ALL logs regardless of current sync mode setting
        if ($direction) {
            $directionMap = [
                'odoo_to_shopify' => ['odoo_to_shopify', 'erp_to_ecom', 'erp_to_shopify'],
                'shopify_to_odoo' => ['shopify_to_odoo', 'ecom_to_erp', 'shopify_to_erp'],
                'erp_to_ecom'     => ['erp_to_ecom', 'odoo_to_shopify', 'erp_to_shopify'],
                'ecom_to_erp'     => ['ecom_to_erp', 'shopify_to_odoo', 'shopify_to_erp'],
            ];
            $directions = $directionMap[$direction] ?? [$direction];
            $query->whereIn('direction', $directions);
        }

        if ($entityType) $query->where('entity_type', $entityType);
        if ($status)     $query->where('status', $status);
        if ($dateFrom)   $query->whereDate('created_at', '>=', $dateFrom);
        if ($dateTo)     $query->whereDate('created_at', '<=', $dateTo);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('entity_id', 'like', "%{$search}%")
                  ->orWhere('error_message', 'like', "%{$search}%")
                  ->orWhere('job_id', 'like', "%{$search}%");
            });
        }

        $logs        = $query->paginate($perPage)->withQueryString();
        $entityTypes = SyncLog::distinct()->pluck('entity_type')->sort()->values();
        $summary     = SyncLog::selectRaw('status, COUNT(*) as total')
                           ->groupBy('status')
                           ->pluck('total', 'status');

        $erpDisplayName  = $this->settings->erpDisplayName();
        $ecomDisplayName = $this->settings->ecomDisplayName();
        $syncMode        = $this->settings->salesOrderSyncMode();

        return view('dashboard.logs', compact(
            'logs', 'direction', 'entityType', 'status', 'search',
            'dateFrom', 'dateTo', 'entityTypes', 'summary', 'syncMode',
            'erpDisplayName', 'ecomDisplayName', 'perPage'
        ));
    }

    public function show(SyncLog $log)
    {
        return view('dashboard.log-detail', compact('log'));
    }
}