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
        $syncMode    = $this->settings->productSyncMode();
        $direction   = $request->input('direction');
        $entityType  = $request->input('entity_type');
        $status      = $request->input('status');
        $search      = $request->input('search');
        $dateFrom    = $request->input('date_from');
        $dateTo      = $request->input('date_to');
        $perPage     = (int) $request->input('per_page', 60);

        $query = SyncLog::orderByDesc('created_at');

        // Filter by sync mode if not bidirectional
        if (!$direction) {
            if ($syncMode === 'erp_to_ecom') {
                $query->where('direction', 'odoo_to_shopify');
            } elseif ($syncMode === 'ecom_to_erp') {
                $query->where('direction', 'shopify_to_odoo');
            }
            // else bidirectional: show both
        } else {
            // User explicitly selected a direction filter
            $query->where('direction', $direction);
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

        $logs = $query->paginate($perPage)->withQueryString();

        // Filter options
        $entityTypes = SyncLog::distinct()->pluck('entity_type')->sort()->values();

        // Status summary (based on current filters)
        $summary = SyncLog::selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Display names
        $erpDisplayName = ucfirst($this->settings->erpDriver() ?? 'ERP');
        $ecomDisplayName = ucfirst($this->settings->ecomDriver() ?? 'Ecom');

        return view('dashboard.logs', compact(
            'logs', 
            'direction', 
            'entityType', 
            'status', 
            'search',
            'dateFrom', 
            'dateTo', 
            'entityTypes', 
            'summary', 
            'syncMode',
            'erpDisplayName',
            'ecomDisplayName',
            'perPage'
        ));
    }

    public function show(SyncLog $log)
    {
        return view('dashboard.log-detail', compact('log'));
    }
}