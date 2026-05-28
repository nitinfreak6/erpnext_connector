@extends('dashboard.layout')

@section('title', 'Product Cache')
@section('page-title', 'Products Listing')

@section('content')
<div x-data="{ selected: [], selectAll: false }"
     x-on:change="selectAll ? selected = {{ json_encode($products->pluck('erp_id')->toArray()) ?? json_encode($products->pluck('odoo_id')->toArray()) }} : selected = []">

{{-- ── Stats bar ── --}}
<div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-5">
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <div class="text-2xl font-bold text-gray-800">{{ $stats['total'] }}</div>
        <div class="text-xs text-gray-500 mt-0.5">Total Cached</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <div class="text-2xl font-bold text-emerald-600">{{ $stats['shopify_sent'] }}</div>
        <div class="text-xs text-gray-500 mt-0.5">{{ $ecomDisplayName }} Sent</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <div class="text-2xl font-bold text-orange-500">{{ $stats['amazon_sent'] }}</div>
        <div class="text-xs text-gray-500 mt-0.5">Amazon Sent</div>
    </div>
    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
        <div class="text-2xl font-bold text-red-500">{{ $stats['shopify_failed'] + $stats['amazon_failed'] }}</div>
        <div class="text-xs text-gray-500 mt-0.5">Failed</div>
    </div>
</div>

{{-- ── Action bar ── --}}
<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
    {{-- Filters --}}
    <form method="GET" action="{{ route('dashboard.product-cache.index') }}"
          class="flex flex-wrap items-center gap-2">
        <input type="text" name="search" value="{{ $search }}" placeholder="Search name / SKU..."
               class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 focus:ring-2 focus:ring-indigo-300 outline-none w-48">

        <select name="ecom_status" class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 outline-none">
            <option value="">{{ $ecomDisplayName }}: All</option>
            <option value="pending" {{ $shopifyFilter==='pending' ? 'selected' : '' }}>Pending</option>
            <option value="sent"    {{ $shopifyFilter==='sent'    ? 'selected' : '' }}>Sent</option>
            <option value="failed"  {{ $shopifyFilter==='failed'  ? 'selected' : '' }}>Failed</option>
        </select>

        <select name="amazon" class="text-sm border border-gray-300 rounded-lg px-3 py-1.5 outline-none">
            <option value="">Amazon: All</option>
            <option value="pending" {{ $amazonFilter==='pending' ? 'selected' : '' }}>Pending</option>
            <option value="sent"    {{ $amazonFilter==='sent'    ? 'selected' : '' }}>Sent</option>
            <option value="failed"  {{ $amazonFilter==='failed'  ? 'selected' : '' }}>Failed</option>
        </select>

        <button type="submit" class="text-sm bg-gray-800 text-white px-3 py-1.5 rounded-lg">Filter</button>
        <a href="{{ route('dashboard.product-cache.index') }}" class="text-sm text-gray-400 hover:text-gray-600">Clear</a>
    </form>

    {{-- Action buttons --}}
    <div class="flex flex-wrap items-center gap-2">

        {{-- Fetch from Odoo --}}
        <form method="POST" action="{{ route('dashboard.product-cache.fetch') }}">
            @csrf
            <button type="submit"
                    class="inline-flex items-center gap-1.5 text-sm bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-1.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                Fetch Products
            </button>
        </form>

        {{-- Post to Shopify --}}
        <form method="POST" action="{{ route('dashboard.product-cache.post-ecom') }}" id="ecomForm">
            @csrf
            <template x-for="id in selected">
                <input type="hidden" name="ids[]" :value="id">
            </template>
            <button type="submit"
                    class="inline-flex items-center gap-1.5 text-sm bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l4-4m0 0l4 4m-4-4v12"/>
                </svg>
                Post to {{ $ecomDisplayName }}
                <span x-show="selected.length > 0" class="bg-white text-green-700 rounded-full px-1.5 text-xs font-bold"
                      x-text="selected.length"></span>
            </button>
        </form>

        {{-- Post to Amazon --}}
        <form method="POST" action="{{ route('dashboard.product-cache.post-amazon') }}" id="amazonForm">
            @csrf
            <template x-for="id in selected">
                <input type="hidden" name="ids[]" :value="id">
            </template>
            <button type="submit"
                    class="inline-flex items-center gap-1.5 text-sm bg-orange-500 hover:bg-orange-600 text-white px-3 py-1.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l4-4m0 0l4 4m-4-4v12"/>
                </svg>
                Post Amazon
                <span x-show="selected.length > 0" class="bg-white text-orange-600 rounded-full px-1.5 text-xs font-bold"
                      x-text="selected.length"></span>
            </button>
        </form>

        {{-- Clear all --}}
        <form method="POST" action="{{ route('dashboard.product-cache.clear-all') }}"
              onsubmit="return confirm('Clear ALL cached products?')">
            @csrf @method('DELETE')
            <button type="submit"
                    class="text-sm border border-gray-300 text-gray-500 hover:bg-gray-50 px-3 py-1.5 rounded-lg transition">
                Clear Cache
            </button>
        </form>
    </div>
</div>

{{-- ── Table ── --}}
<div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
    @if($products->isEmpty())
        <div class="py-16 text-center">
            <svg class="w-10 h-10 text-gray-300 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                      d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
            </svg>
            <p class="text-sm text-gray-400 font-medium">No products cached yet</p>
            <p class="text-xs text-gray-300 mt-1">Click "Fetch Products" to pull from {{ $erpDisplayName }}</p>
        </div>
    @else
    <table class="w-full text-sm">
        <thead>
            <tr class="bg-gray-50 border-b border-gray-200 text-xs font-semibold text-gray-500 uppercase tracking-wider">
                <th class="px-4 py-3 w-8">
                    <input type="checkbox" x-model="selectAll"
                           class="rounded text-indigo-600 cursor-pointer">
                </th>
                <th class="px-4 py-3 text-left">#</th>
                <th class="px-4 py-3 text-left">{{ $erpDisplayName }} ID</th>
                <th class="px-4 py-3 text-left">Product Name</th>
                <th class="px-4 py-3 text-left">SKU</th>
                <th class="px-4 py-3 text-left">{{ $ecomDisplayName }}</th>
                <th class="px-4 py-3 text-left">Amazon</th>
                <th class="px-4 py-3 text-left">Fetched At</th>
                <th class="px-4 py-3 text-right">Actions</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @foreach($products as $i => $product)
            <tr class="hover:bg-gray-50 transition">
                {{-- Checkbox --}}
                <td class="px-4 py-3">
                    <input type="checkbox" :value="{{ $product->odoo_id }}" x-model="selected"
                           class="rounded text-indigo-600 cursor-pointer">
                </td>

                {{-- Row number --}}
                <td class="px-4 py-3 text-gray-400 text-xs">{{ $products->firstItem() + $i }}</td>

                {{-- ERP ID --}}
                <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $product->erp_id ?? $product->odoo_id }}</td>

                {{-- Name --}}
                <td class="px-4 py-3">
                    <div class="font-medium text-gray-800">{{ $product->name }}</div>
                </td>

                {{-- SKU --}}
                <td class="px-4 py-3 font-mono text-xs text-gray-500">
                    {{ $product->default_code ?: '—' }}
                </td>

                {{-- Ecom status --}}
                <td class="px-4 py-3">
                    <div class="flex flex-col gap-1">
                        <span class="badge {{ $product->statusBadgeClass('ecom') }}">
                            {{ ucfirst($product->ecom_status ?? $product->shopify_status) }}
                        </span>
                        @if($product->ecom_message ?? $product->shopify_message)
                        <span class="text-xs text-red-500 truncate max-w-24" title="{{ $product->ecom_message ?? $product->shopify_message }}">
                            {{ Str::limit($product->ecom_message ?? $product->shopify_message, 30) }}
                        </span>
                        @endif
                    </div>
                </td>

                {{-- Amazon status --}}
                <td class="px-4 py-3">
                    <div class="flex flex-col gap-1">
                        <span class="badge {{ $product->statusBadgeClass('amazon') }}">
                            {{ ucfirst($product->amazon_status) }}
                        </span>
                        @if($product->amazon_message)
                        <span class="text-xs text-red-500 truncate max-w-24" title="{{ $product->amazon_message }}">
                            {{ Str::limit($product->amazon_message, 30) }}
                        </span>
                        @endif
                    </div>
                </td>

                {{-- Fetched at --}}
                <td class="px-4 py-3 text-xs text-gray-400">
                    {{ $product->fetched_at?->format('Y-m-d H:i') ?? '—' }}
                </td>

                {{-- Actions --}}
                <td class="px-4 py-3 text-right">
                    <div class="flex items-center justify-end gap-1">
                        {{-- View raw data --}}
                        <a href="{{ route('dashboard.product-cache.show', $product->erp_id ?? $product->odoo_id) }}"
                           class="text-indigo-500 hover:text-indigo-700 p-1 rounded hover:bg-indigo-50 transition" title="View {{ $erpDisplayName }} Data">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                      d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                            </svg>
                        </a>

                        {{-- Refresh cache --}}
                        <form method="POST" action="{{ route('dashboard.product-cache.refresh', $product->erp_id ?? $product->odoo_id) }}">
                            @csrf
                            <button type="submit"
                                    class="text-gray-400 hover:text-gray-700 p-1 rounded hover:bg-gray-100 transition" title="Refresh from {{ $erpDisplayName }}">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                                </svg>
                            </button>
                        </form>

                        {{-- Clear --}}
                        <form method="POST" action="{{ route('dashboard.product-cache.clear', $product->erp_id ?? $product->odoo_id) }}"
                              onsubmit="return confirm('Clear cache for this product?')">
                            @csrf @method('DELETE')
                            <button type="submit"
                                    class="text-red-400 hover:text-red-600 p-1 rounded hover:bg-red-50 transition" title="Clear Cache">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                </svg>
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @if($products->hasPages())
    <div class="px-4 py-3 border-t border-gray-100 flex items-center justify-between">
        <p class="text-xs text-gray-500">
            Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }}
        </p>
        {{ $products->links() }}
    </div>
    @endif
    @endif
</div>
</div>
@endsection