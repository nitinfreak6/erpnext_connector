<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Jobs\Amazon\PushProductToAmazonJob;
use App\Models\ProductCache;
use App\Services\ProductCacheService;
use App\Services\SettingsService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProductCacheController extends Controller
{
    public function __construct(
        private readonly ProductCacheService $cache,
        private readonly SettingsService     $settings,
    ) {}

    public function index(Request $request): View
    {
        $ecomFilter   = $request->query('ecom_status') ?? $request->query('shopify');
        $amazonFilter = $request->query('amazon');
        $search       = $request->query('search');

        $products = ProductCache::query()
            ->when($ecomFilter, fn($q) => $q->ecomStatus($ecomFilter))
            ->when($amazonFilter, fn($q) => $q->where('amazon_status', $amazonFilter))
            ->when($search, fn($q) => $q->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('default_code', 'like', "%{$search}%")
                  ->orWhereRaw('COALESCE(erp_id, odoo_id) = ?', [$search]);
            }))
            ->orderByDesc('fetched_at')
            ->paginate(25)
            ->withQueryString();

        $stats = [
            'total'           => ProductCache::count(),
            'ecom_sent'       => ProductCache::countEcomStatus('sent'),
            'ecom_failed'     => ProductCache::countEcomStatus('failed'),
            'ecom_pending'    => ProductCache::countEcomStatus('pending'),
            'amazon_sent'     => ProductCache::where('amazon_status', 'sent')->count(),
            'amazon_failed'   => ProductCache::where('amazon_status', 'failed')->count(),
            'amazon_pending'  => ProductCache::where('amazon_status', 'pending')->count(),
            // Old key aliases so any blade referencing shopify_* still works
            'shopify_sent'    => ProductCache::countEcomStatus('sent'),
            'shopify_failed'  => ProductCache::countEcomStatus('failed'),
            'shopify_pending' => ProductCache::countEcomStatus('pending'),
        ];

        $shopifyFilter = $ecomFilter; // blade compat

        return view('dashboard.products.cache', compact(
            'products', 'stats', 'ecomFilter', 'shopifyFilter', 'amazonFilter', 'search'
        ));
    }

    public function show(int $erpId): View
    {
        $erpCol = ProductCache::erpIdColumn();
        $cacheRecord = ProductCache::where($erpCol, $erpId)->firstOrFail();

        $data = $cacheRecord->readCache();

        return view('dashboard.products.cache-detail', compact('cacheRecord', 'data'));
    }

    public function fetchAll(): RedirectResponse
    {
        try {
            $count = $this->cache->fetchAndCacheAll();
            return back()->with('success', "Fetched and cached {$count} products from {$this->settings->erpDisplayName()}.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Fetch failed: ' . $e->getMessage());
        }
    }

    public function refresh(int $erpId): RedirectResponse
    {
        try {
            $this->cache->fetchAndCacheSingle($erpId);
            return back()->with('success', "Product #{$erpId} cache refreshed.");
        } catch (\Throwable $e) {
            return back()->with('error', 'Refresh failed: ' . $e->getMessage());
        }
    }

    /**
     * FIX: postEcom() replaces postShopify() — uses active ecom driver.
     * Route: POST /product-cache/post-ecom  (name: .post-ecom)
     */
    public function postEcom(Request $request): RedirectResponse
    {
        $ecomDriver = $this->settings->ecomDriver();
        $ecomJobMap = [
            'shopify' => \App\Jobs\Ecom\PushProductToEcomJob::class,
            // 'woocommerce' => \App\Jobs\WooCommerce\PushProductToWooCommerceJob::class,
        ];

        if (!isset($ecomJobMap[$ecomDriver])) {
            return back()->with('error', "No push job registered for driver [{$ecomDriver}].");
        }

        $ecomJobClass = $ecomJobMap[$ecomDriver];

        // FIX: use erp_id ?? odoo_id
        $ids = array_filter(array_map('intval', $request->input('ids', [])));

        if (!empty($ids)) {
            foreach ($ids as $erpId) {
                $ecomJobClass::dispatch($erpId)->onQueue('sync');
            }
            return back()->with('success', count($ids) . ' product(s) queued for ' . $this->settings->ecomDisplayName() . '.');
        }

        // No selection — queue all non-sent using model helper
        $pendingIds = ProductCache::pendingEcomIds();

        if (empty($pendingIds)) {
            return back()->with('success', "All products already sent to {$this->settings->ecomDisplayName()}.");
        }

        foreach ($pendingIds as $erpId) {
            $ecomJobClass::dispatch($erpId)->onQueue('sync');
        }

        return back()->with('success', count($pendingIds) . ' product(s) queued for ' . $this->settings->ecomDisplayName() . '.');
    }

    /**
     * Kept for backwards compat — delegates to postEcom()
     */
    public function postShopify(Request $request): RedirectResponse
    {
        return $this->postEcom($request);
    }

    public function postAmazon(Request $request): RedirectResponse
    {
        $ids = array_filter(array_map('intval', $request->input('ids', [])));

        if (!empty($ids)) {
            foreach ($ids as $erpId) {
                PushProductToAmazonJob::dispatch($erpId)->onQueue('sync');
            }
            return back()->with('success', count($ids) . ' product(s) queued for Amazon.');
        }

        $erpCol = ProductCache::erpIdColumn();
        $pendingIds = ProductCache::where('amazon_status', '!=', 'sent')
            ->orWhereNull('amazon_status')
            ->pluck($erpCol)
            ->map(fn($id) => (int) $id)
            ->filter()
            ->toArray();

        if (empty($pendingIds)) {
            return back()->with('success', 'All products already sent to Amazon.');
        }

        foreach ($pendingIds as $erpId) {
            PushProductToAmazonJob::dispatch($erpId)->onQueue('sync');
        }

        return back()->with('success', count($pendingIds) . ' product(s) queued for Amazon.');
    }

    public function clear(int $erpId): RedirectResponse
    {
        $this->cache->clearCache($erpId);
        return back()->with('success', "Cache cleared for product #{$erpId}.");
    }

    public function clearAll(): RedirectResponse
    {
        $count = $this->cache->clearAll();
        return back()->with('success', "Cleared cache for {$count} products.");
    }
}
