@extends('dashboard.layout')

@section('title', 'Product Data — ' . $cacheRecord->name)
@section('page-title', 'Product Data')

@section('content')

{{-- ── Header ── --}}
<div class="flex items-center justify-between mb-5">
    <div class="flex items-center gap-3">
        <a href="{{ route('dashboard.product-cache.index') }}"
           class="text-gray-400 hover:text-gray-600 transition">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
            </svg>
        </a>
        <div>
            <h2 class="text-lg font-semibold text-gray-800">{{ $cacheRecord->name }}</h2>
            <p class="text-xs text-gray-400">
                {{ $erpDisplayName }} ID: {{ $cacheRecord->erp_id ?? $cacheRecord->odoo_id }}
                @if($cacheRecord->default_code) · SKU: {{ $cacheRecord->default_code }} @endif
                · Fetched: {{ $cacheRecord->fetched_at?->format('Y-m-d H:i:s') }}
            </p>
        </div>
    </div>

    <div class="flex items-center gap-2">
        {{-- Refresh --}}
        <form method="POST" action="{{ route('dashboard.product-cache.refresh', $cacheRecord->erp_id ?? $cacheRecord->odoo_id) }}">
            @csrf
            <button type="submit"
                    class="text-sm border border-gray-300 text-gray-600 hover:bg-gray-50 px-3 py-1.5 rounded-lg transition flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Re-fetch from {{ $erpDisplayName }}
            </button>
        </form>

        {{-- Post to Ecom --}}
        <form method="POST" action="{{ route('dashboard.product-cache.post-ecom') }}">
            @csrf
            <input type="hidden" name="ids[]" value="{{ $cacheRecord->erp_id ?? $cacheRecord->odoo_id }}">
            <button type="submit"
                    class="text-sm bg-green-600 hover:bg-green-700 text-white px-3 py-1.5 rounded-lg transition flex items-center gap-1.5">
                Post to {{ $ecomDisplayName }}
            </button>
        </form>

        {{-- Post Amazon --}}
        <form method="POST" action="{{ route('dashboard.product-cache.post-amazon') }}">
            @csrf
            <input type="hidden" name="ids[]" value="{{ $cacheRecord->erp_id ?? $cacheRecord->odoo_id }}">
            <button type="submit"
                    class="text-sm bg-orange-500 hover:bg-orange-600 text-white px-3 py-1.5 rounded-lg transition flex items-center gap-1.5">
                Post Amazon
            </button>
        </form>
    </div>
</div>

{{-- ── Status cards ── --}}
<div class="grid grid-cols-2 gap-3 mb-5">
    <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
        <div class="w-8 h-8 bg-green-100 rounded-lg flex items-center justify-center shrink-0">
            <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 24 24">
                <path d="M1.5 8.5c0-1.657 3.582-3 8-3s8 1.343 8 3v2c0 1.657-3.582 3-8 3s-8-1.343-8-3v-2z"/>
            </svg>
        </div>
        <div>
            <div class="text-xs text-gray-500">{{ $ecomDisplayName }} Status</div>
            <span class="badge {{ $cacheRecord->statusBadgeClass('ecom') }} mt-0.5">
                {{ ucfirst($cacheRecord->ecom_status ?? $cacheRecord->shopify_status) }}
            </span>
            @if($cacheRecord->ecom_synced_at ?? $cacheRecord->shopify_synced_at)
            <div class="text-xs text-gray-400 mt-0.5">{{ $cacheRecord->ecom_synced_at ?? $cacheRecord->shopify_synced_at->format('Y-m-d H:i') }}</div>
            @endif
            @if($cacheRecord->ecom_message ?? $cacheRecord->shopify_message)
            <div class="text-xs text-red-500 mt-0.5">{{ $cacheRecord->ecom_message ?? $cacheRecord->shopify_message }}</div>
            @endif
        </div>
    </div>

    <div class="bg-white rounded-xl border border-gray-200 p-4 flex items-center gap-3">
        <div class="w-8 h-8 bg-orange-100 rounded-lg flex items-center justify-center shrink-0">
            <svg class="w-4 h-4 text-orange-500" fill="currentColor" viewBox="0 0 24 24">
                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/>
            </svg>
        </div>
        <div>
            <div class="text-xs text-gray-500">Amazon Status</div>
            <span class="badge {{ $cacheRecord->statusBadgeClass('amazon') }} mt-0.5">
                {{ ucfirst($cacheRecord->amazon_status) }}
            </span>
            @if($cacheRecord->amazon_synced_at)
            <div class="text-xs text-gray-400 mt-0.5">{{ $cacheRecord->amazon_synced_at->format('Y-m-d H:i') }}</div>
            @endif
            @if($cacheRecord->amazon_message)
            <div class="text-xs text-red-500 mt-0.5">{{ $cacheRecord->amazon_message }}</div>
            @endif
        </div>
    </div>
</div>

{{-- ── Raw {{ $erpDisplayName }} Data ── --}}
@if($data)
<div class="space-y-4">

    {{-- Template --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="bg-gray-50 border-b border-gray-200 px-4 py-2.5 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-700">Product Template ({{ $erpDisplayName }})</h3>
            <span class="text-xs text-gray-400">product.template</span>
        </div>
        <div class="p-4">
            <table class="w-full text-sm">
                @foreach($data['template'] as $key => $value)
                <tr class="border-b border-gray-50 last:border-0">
                    <td class="py-1.5 pr-4 text-xs font-mono text-gray-400 w-48">{{ $key }}</td>
                    <td class="py-1.5 text-gray-700 break-all">
                        @if(is_array($value))
                            <span class="text-indigo-500">{{ json_encode($value) }}</span>
                        @elseif(is_bool($value))
                            <span class="{{ $value ? 'text-emerald-600' : 'text-red-500' }}">{{ $value ? 'true' : 'false' }}</span>
                        @elseif($value === null || $value === false)
                            <span class="text-gray-300">null</span>
                        @else
                            {{ $value }}
                        @endif
                    </td>
                </tr>
                @endforeach
            </table>
        </div>
    </div>

    {{-- Variants --}}
    @if(!empty($data['variants']))
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="bg-gray-50 border-b border-gray-200 px-4 py-2.5 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-700">Variants ({{ count($data['variants']) }})</h3>
            <span class="text-xs text-gray-400">product.product</span>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-xs">
                <thead>
                    <tr class="bg-gray-50 border-b border-gray-100">
                        <th class="px-3 py-2 text-left font-semibold text-gray-500">ID</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Name</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-500">SKU</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Barcode</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Price</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Weight</th>
                        <th class="px-3 py-2 text-left font-semibold text-gray-500">Attributes</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @foreach($data['variants'] as $variant)
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 font-mono text-gray-500">{{ $variant['id'] }}</td>
                        <td class="px-3 py-2 text-gray-700">{{ $variant['name'] ?? '—' }}</td>
                        <td class="px-3 py-2 font-mono text-indigo-600">{{ $variant['default_code'] ?? '—' }}</td>
                        <td class="px-3 py-2 font-mono text-gray-500">{{ $variant['barcode'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-gray-700">{{ $variant['lst_price'] ?? $variant['list_price'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-gray-500">{{ $variant['weight'] ?? '—' }}</td>
                        <td class="px-3 py-2 text-gray-500">
                            {{ json_encode($variant['product_template_attribute_value_ids'] ?? []) }}
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Product Attributes (custom fields) --}}
    @if(!empty($data['product_attributes']))
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
        <div class="bg-gray-50 border-b border-gray-200 px-4 py-2.5">
            <h3 class="text-sm font-semibold text-gray-700">Product Attributes / Custom Fields</h3>
        </div>
        <div class="p-4">
            <table class="w-full text-sm">
                @foreach($data['product_attributes'] as $key => $value)
                <tr class="border-b border-gray-50 last:border-0">
                    <td class="py-1.5 pr-4 text-xs font-mono text-gray-400 w-48">{{ $key }}</td>
                    <td class="py-1.5 text-gray-700">{{ is_array($value) ? json_encode($value) : ($value ?? '—') }}</td>
                </tr>
                @endforeach
            </table>
        </div>
    </div>
    @endif

    {{-- Raw JSON --}}
    <div class="bg-white rounded-xl border border-gray-200 overflow-hidden" x-data="{ show: false }">
        <div class="bg-gray-50 border-b border-gray-200 px-4 py-2.5 flex items-center justify-between">
            <h3 class="text-sm font-semibold text-gray-700">Raw JSON (full cache file)</h3>
            <button @click="show = !show" class="text-xs text-indigo-500 hover:text-indigo-700">
                <span x-text="show ? 'Hide' : 'Show'">Show</span>
            </button>
        </div>
        <div x-show="show" x-cloak class="p-4">
            <pre class="text-xs font-mono text-gray-600 bg-gray-50 rounded-lg p-4 overflow-x-auto max-h-96">{{ json_encode($data, JSON_PRETTY_PRINT) }}</pre>
        </div>
    </div>
</div>
@else
<div class="bg-yellow-50 border border-yellow-200 rounded-xl p-6 text-center">
    <p class="text-sm text-yellow-700">Cache file not found on disk.</p>
    <form method="POST" action="{{ route('dashboard.product-cache.refresh', $cacheRecord->erp_id ?? $cacheRecord->odoo_id) }}" class="mt-3">
        @csrf
        <button type="submit" class="text-sm bg-yellow-600 text-white px-4 py-1.5 rounded-lg hover:bg-yellow-700">
            Re-fetch from {{ $erpDisplayName }}
        </button>
    </form>
</div>
@endif

@endsection