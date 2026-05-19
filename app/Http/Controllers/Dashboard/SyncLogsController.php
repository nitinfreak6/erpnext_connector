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

        $query = SyncLog::orderByDesc('created_at');

        // Filter by sync mode if not bidirectional
        if (!$direction) {
            if ($syncMode === 'erp_to_ecom') {
                $query->where('direction', 'erp_to_ecom');
            } elseif ($syncMode === 'ecom_to_erp') {
                $query->where('direction', 'ecom_to_erp');
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

        $logs = $query->paginate(60)->withQueryString();

        // Filter options
        $entityTypes = SyncLog::distinct()->pluck('entity_type')->sort()->values();

        // Status summary (based on current filters)
        $summaryQuery = SyncLog::query();
        if ($syncMode === 'erp_to_ecom' && !$direction) {
            $summaryQuery->where('direction', 'erp_to_ecom');
        } elseif ($syncMode === 'ecom_to_erp' && !$direction) {
            $summaryQuery->where('direction', 'ecom_to_erp');
        }

        $summary = $summaryQuery->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Direction summary for bidirectional mode
        $directionSummary = [];
        if ($syncMode === 'bidirectional') {
            $directionSummary = SyncLog::selectRaw('direction, COUNT(*) as total')
                ->whereIn('direction', ['erp_to_ecom', 'ecom_to_erp'])
                ->groupBy('direction')
                ->pluck('total', 'direction');
        }

        $ecomDriver = $this->settings->ecomDriver();

        return view('dashboard.logs', compact(
            'logs', 'direction', 'entityType', 'status', 'search',
            'dateFrom', 'dateTo', 'entityTypes', 'summary', 'syncMode',
            'directionSummary', 'ecomDriver'
        ));
    }

    public function show(SyncLog $log)
    {
        return view('dashboard.log-detail', compact('log'));
    }
}