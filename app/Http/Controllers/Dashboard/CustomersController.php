<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\Ecom\FetchEcomCustomersJob;
use App\Jobs\Erp\FetchErpCustomersJob;
use App\Models\SyncMapping;
use App\Models\SyncLog;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * CustomersController - Driver-agnostic customer sync dashboard
 * 
 * Works with ANY ERP (Odoo, SAP, NetSuite) and ANY Ecom (Shopify, WooCommerce, Magento)
 * via ErpInterface and EcomInterface - NO hardcoded references
 */
class CustomersController extends Controller
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    public function index(Request $request)
    {
        $syncMode = $this->settings->customerSyncMode();
        $search = $request->input('search', '');
        $status = $request->input('status', 'all');
        $perPage = (int) $request->input('per_page', 25);
        $direction = $request->input('direction', 'erp_to_ecom');

        $customers = match($syncMode) {
            'erp_to_ecom' => $this->getErpToEcomCustomers($search, $status, $perPage),
            'ecom_to_erp' => $this->getEcomToErpCustomers($search, $status, $perPage),
            'bidirectional' => $this->getBidirectionalCustomers($search, $status, $perPage, $direction),
            default => collect([]),
        };

        $stats = match($syncMode) {
            'erp_to_ecom' => $this->getErpToEcomStats(),
            'ecom_to_erp' => $this->getEcomToErpStats(),
            'bidirectional' => $this->getBidirectionalStats(),
            default => [],
        };
		
	

        $ecomDriver = $this->settings->ecomDriver();
        $erpDriver = $this->settings->erpDriver();

        return view('dashboard.customers', compact(
            'customers', 'search', 'status', 'perPage', 'stats', 
            'syncMode', 'ecomDriver', 'erpDriver', 'direction'
        ));
    }

    private function getErpToEcomCustomers(?string $search, string $status, int $perPage)
    {
        $query = SyncMapping::where('entity_type', 'customer')
            ->where('last_sync_direction', 'erp_to_ecom')
            ->orderByDesc('last_synced_at');



        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('erp_id', 'like', "%{$search}%")
                  ->orWhere('ecom_id', 'like', "%{$search}%")
                  ->orWhere('ecom_handle', 'like', "%{$search}%");
            });
        }

        $query->addSelect([
            'latest_log_status' => SyncLog::select('status')
                ->whereColumn('entity_id', 'sync_mappings.erp_id')
                ->where('entity_type', 'customer')
                ->where('direction', 'erp_to_ecom')
                ->latest()
                ->limit(1)
        ]);

        if ($status !== 'all') {
            $query->having('latest_log_status', $status);
        }

        return $query->paginate($perPage)->withQueryString();
    }

    private function getEcomToErpCustomers(?string $search, string $status, int $perPage)
    {
        $query = SyncMapping::where('sync_mappings.entity_type', 'customer')
            ->where('sync_mappings.last_sync_direction', 'ecom_to_erp')
            ->orderByDesc('sync_mappings.last_synced_at');

        if ($search) {
            $query->where(function($q) use ($search) {
                $q->where('sync_mappings.ecom_id', 'like', "%{$search}%")
                  ->orWhere('sync_mappings.erp_id', 'like', "%{$search}%")
                  ->orWhere('sync_mappings.ecom_handle', 'like', "%{$search}%");
            });
        }

        $results = $query->paginate($perPage)->withQueryString();
        
        $results->getCollection()->transform(function($customer) {
            $latestLog = SyncLog::where('entity_id', $customer->ecom_id)
                ->where('entity_type', 'customer')
                ->where('direction', 'ecom_to_erp')
                ->latest()
                ->first();
            
            $customer->latest_log_status = $latestLog?->status ?? 'pending';
            return $customer;
        });

        return $results;
    }

    private function getBidirectionalCustomers(?string $search, string $status, int $perPage, string $direction)
    {
        return $direction === 'ecom_to_erp' 
            ? $this->getEcomToErpCustomers($search, $status, $perPage)
            : $this->getErpToEcomCustomers($search, $status, $perPage);
    }

    private function getErpToEcomStats(): array
    {
        $query = SyncMapping::where('entity_type', 'customer')
            ->where('last_sync_direction', 'erp_to_ecom');

        return [
            'total' => $query->count(),
            'synced' => $query->whereNotNull('ecom_id')->count(),
            'pending' => $query->whereNull('ecom_id')->count(),
        ];
    }

    private function getEcomToErpStats(): array
    {
        $total = SyncMapping::where('entity_type', 'customer')
            ->where('last_sync_direction', 'ecom_to_erp')
            ->count();

        $success = DB::table('sync_mappings')
            ->join('sync_logs', function($join) {
                $join->on('sync_logs.entity_id', '=', 'sync_mappings.ecom_id')
                     ->where('sync_logs.entity_type', 'customer')
                     ->where('sync_logs.direction', 'ecom_to_erp')
                     ->whereRaw('sync_logs.id = (
                         SELECT id FROM sync_logs sl2 
                         WHERE sl2.entity_id = sync_mappings.ecom_id 
                         AND sl2.entity_type = "customer"
                         ORDER BY created_at DESC LIMIT 1
                     )');
            })
            ->where('sync_logs.status', 'success')
            ->count();

        return [
            'total' => $total,
            'success' => $success,
            'pending' => max(0, $total - $success),
        ];
    }

    private function getBidirectionalStats(): array
    {
        return [
            'erp_to_ecom' => $this->getErpToEcomStats(),
            'ecom_to_erp' => $this->getEcomToErpStats(),
            'total' => SyncMapping::where('entity_type', 'customer')->count(),
        ];
    }

    /**
     * Fetch FROM ERP - uses ErpInterface (works with any ERP: Odoo/SAP/NetSuite)
     */
    public function fetch()
    {
        if ($this->settings->customerSyncMode() === 'ecom_to_erp') {
            return back()->with('error', 'Cannot fetch from ERP when sync mode is Ecom → ERP.');
        }

        FetchErpCustomersJob::dispatchSync();
        
        return back()->with('success', sprintf(
            'Customer fetch from %s queued successfully.', 
            $this->settings->erpDisplayName()
        ));
    }

    /**
     * Pull FROM Ecom - uses EcomInterface (works with any ecom: Shopify/WooCommerce/Magento)
     */
    public function pull()
    {
        if ($this->settings->customerSyncMode() === 'erp_to_ecom') {
            return back()->with('error', 'Cannot pull from Ecom when sync mode is ERP → Ecom.');
        }

        FetchEcomCustomersJob::dispatchSync();
        
        return back()->with('success', sprintf(
            'Pull from %s queued successfully.', 
            ucfirst($this->settings->ecomDriver())
        ));
    }
}