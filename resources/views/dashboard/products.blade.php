@extends('dashboard.layout')
@section('title', 'Products')
@section('page-title', 'Products Sync')

@section('content')

{{-- Flash messages --}}
@if(session('success'))
<div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
    {{ session('success') }}
</div>
@endif
@if(session('error'))
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
    {{ session('error') }}
</div>
@endif

{{-- Header actions --}}
<div class="flex items-center justify-between mb-4">
    <div class="text-xs text-gray-400">
        Odoo is source of truth · Products cached as JSON files · Pushes read from cache
    </div>
    <div class="flex items-center gap-2">
        {{-- Fetch Products: calls Odoo ONCE, saves JSON per product --}}
        <form method="POST" action="{{ route('dashboard.products.fetch') }}">
            @csrf
            <button type="submit"
                    onclick="return confirm('This will fetch ALL products from Odoo and cache them locally. Continue?')"
                    class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                </svg>
                Fetch Products
            </button>
        </form>
    </div>
</div>

{{-- Filters --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ $search }}" placeholder="SKU, Odoo ID, Shopify ID…"
                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm w-64 focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Channel</label>
            <select name="channel" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="all"     {{ $channel === 'all'     ? 'selected' : '' }}>All Channels</option>
                <option value="shopify" {{ $channel === 'shopify' ? 'selected' : '' }}>Shopify</option>
                <option value="amazon"  {{ $channel === 'amazon'  ? 'selected' : '' }}>Amazon</option>
            </select>
        </div>
        <button type="submit"
                class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-indigo-700 transition">
            Filter
        </button>
        <a href="{{ route('dashboard.products') }}"
           class="text-sm text-gray-500 hover:text-gray-700 py-1.5">Reset</a>
    </form>
</div>

{{-- Table --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
        <span class="text-sm font-medium text-gray-700">{{ $products->total() }} product mappings</span>
        <span class="text-xs text-gray-400">
            {{ count($cachedIds) }} with cached JSON
        </span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left font-medium">Channel</th>
                    <th class="px-4 py-3 text-left font-medium">Odoo ID</th>
                    <th class="px-4 py-3 text-left font-medium">SKU / Reference</th>
                    <th class="px-4 py-3 text-left font-medium">Shopify / Amazon ID</th>
                    <th class="px-4 py-3 text-left font-medium">Handle / SKU</th>
                    <th class="px-4 py-3 text-left font-medium">Variants</th>
                    <th class="px-4 py-3 text-left font-medium">Last Synced</th>
                    <th class="px-4 py-3 text-left font-medium">Cache</th>
                    <th class="px-4 py-3 text-left font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($products as $mapping)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3">
                        @if(str_starts_with($mapping->entity_type, 'amazon'))
                            <span class="badge bg-amber-100 text-amber-800">Amazon</span>
                        @else
                            <span class="badge bg-indigo-100 text-indigo-800">Shopify</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-700">
                        <span class="bg-gray-100 px-1.5 py-0.5 rounded">#{{ $mapping->odoo_id }}</span>
                    </td>
                    <td class="px-4 py-3 text-gray-700">
                        {{ $mapping->odoo_reference ?: '—' }}
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-700">
                        @if($mapping->shopify_id)
                            @if(str_starts_with($mapping->entity_type, 'amazon'))
                                <span class="text-amber-700">{{ $mapping->shopify_id }}</span>
                            @else
                                <a href="https://admin.shopify.com/products/{{ $mapping->shopify_id }}"
                                   target="_blank"
                                   class="text-indigo-600 hover:underline">{{ $mapping->shopify_id }} ↗</a>
                            @endif
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        {{ $mapping->shopify_handle ?: '—' }}
                    </td>
                    <td class="px-4 py-3">
                        <span class="badge bg-gray-100 text-gray-600">
                            {{ $variantCounts[$mapping->shopify_id] ?? 0 }} vars
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400">
                        {{ $mapping->last_synced_at?->diffForHumans() ?? 'Never' }}
                    </td>
                    <td class="px-4 py-3">
                        @if(in_array((int)$mapping->odoo_id, $cachedIds))
                            <span class="inline-flex items-center gap-1 text-xs text-emerald-600 bg-emerald-50 px-2 py-0.5 rounded-full">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                Cached
                            </span>
                        @else
                            <span class="text-xs text-gray-300">No cache</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-2">
                            {{-- View raw Odoo data from JSON cache --}}
                            @if(in_array((int)$mapping->odoo_id, $cachedIds))
                                <a href="{{ route('dashboard.products.show', $mapping->odoo_id) }}"
                                   title="View cached Odoo data"
                                   class="text-indigo-500 hover:text-indigo-700 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                    </svg>
                                </a>
                            @endif

                            {{-- Refresh cache for this product from Odoo --}}
                            <form method="POST"
                                  action="{{ route('dashboard.products.refresh', $mapping->odoo_id) }}"
                                  style="display:inline">
                                @csrf
                                @method('PATCH')
                                <button type="submit"
                                        title="Re-fetch this product from Odoo and update cache"
                                        class="text-gray-400 hover:text-gray-600 transition">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                              d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                    </svg>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="px-4 py-12 text-center text-gray-400">
                        No product mappings found.
                        Run <code class="bg-gray-100 px-1 rounded">php artisan sync:products --full</code>
                        or click <strong>Fetch Products</strong> above.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100">
        {{ $products->links() }}
    </div>
</div>

{{-- Recent sync logs --}}
<div class="mt-4 bg-white rounded-xl border border-gray-100 shadow-sm p-5">
    <h3 class="text-sm font-semibold text-gray-700 mb-3">Recent Product Sync Activity</h3>
    @include('dashboard._log-rows', ['logs' => $recentLogs])
</div>

@endsection