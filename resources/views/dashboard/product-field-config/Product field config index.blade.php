@extends('dashboard.layout')
@section('title', 'Product Detail')
@section('page-title', 'Product Odoo Data')

@section('content')
<div class="max-w-5xl">
    <div class="mb-4 flex items-center justify-between">
        <a href="{{ route('dashboard.products') }}" class="text-sm text-indigo-600 hover:underline">← Back to Products</a>
        <form method="POST" action="{{ route('dashboard.products.refresh', $odooId) }}">
            @csrf
            @method('PATCH')
            <button type="submit"
                    class="inline-flex items-center gap-1.5 text-sm text-gray-600 border border-gray-200 hover:border-gray-400 px-3 py-1.5 rounded-lg transition">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
                </svg>
                Re-fetch from Odoo
            </button>
        </form>
    </div>

    @if(session('success'))
    <div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg text-sm">{{ session('success') }}</div>
    @endif
    @if(session('error'))
    <div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm">{{ session('error') }}</div>
    @endif

    {{-- Header info bar --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-4">
        <div class="flex items-start justify-between">
            <div>
                <div class="flex items-center gap-2 mb-1">
                    <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700">Odoo Product</span>
                    <span class="font-mono text-sm text-gray-700">#{{ $odooId }}</span>
                    @if(!empty($data['template']['name']))
                        <span class="text-sm font-semibold text-gray-800">{{ $data['template']['name'] }}</span>
                    @endif
                </div>
                <p class="text-xs text-gray-400 mt-1">
                    Cached: {{ \Carbon\Carbon::parse($data['fetched_at'])->format('Y-m-d H:i:s') }}
                    &nbsp;·&nbsp;
                    File: <code class="bg-gray-100 px-1 rounded">storage/app/products/{{ $odooId }}.json</code>
                    &nbsp;·&nbsp;
                    <span class="text-emerald-600 font-medium">No Odoo API call</span>
                </p>
            </div>
            <div class="text-right text-xs text-gray-400 space-y-1">
                <div>Variants: <strong class="text-gray-700">{{ count($data['variants'] ?? []) }}</strong></div>
                <div>Attribute Values: <strong class="text-gray-700">{{ count($data['attribute_values'] ?? []) }}</strong></div>
            </div>
        </div>
    </div>

    {{-- Tabbed panel --}}
    <div x-data="{ tab: 'raw' }" class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">

        {{-- Tab nav --}}
        <div class="flex border-b border-gray-100 bg-gray-50">
            <button @click="tab = 'raw'"
                    :class="tab === 'raw' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                    class="px-5 py-3 text-sm font-medium transition">
                📦 Raw Odoo JSON
            </button>
            <button @click="tab = 'shopify'"
                    :class="tab === 'shopify' ? 'border-b-2 border-indigo-500 text-indigo-600 bg-white' : 'text-gray-500 hover:text-gray-700'"
                    class="px-5 py-3 text-sm font-medium transition">
                🛍 Shopify Payload
            </button>
        </div>

        {{-- RAW JSON tab — full cache file --}}
        <div x-show="tab === 'raw'" class="p-5">
            <p class="text-xs text-gray-400 mb-3">
                Full contents of <code class="bg-gray-100 px-1 rounded">storage/app/products/{{ $odooId }}.json</code>
            </p>
            <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap"
                 style="max-height:72vh">{{ json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>

        {{-- SHOPIFY PAYLOAD tab --}}
        <div x-show="tab === 'shopify'" class="p-5">
            <p class="text-xs text-gray-400 mb-3">
                Preview of what will be sent to Shopify API for this product.
                Based on active
                <a href="{{ route('dashboard.mappings.index', 'product_field') }}"
                   class="text-indigo-500 hover:underline">Product Field Mappings</a>.
            </p>

            @if(!empty($shopifyPayload['_error']))
                <div class="bg-red-50 border border-red-200 rounded-lg p-4 text-sm text-red-700">
                    <strong>Error building payload:</strong> {{ $shopifyPayload['_error'] }}
                </div>
            @elseif(!empty($shopifyPayload))
                <div class="flex items-center gap-3 mb-3">
                    <span class="text-xs bg-indigo-100 text-indigo-700 px-2 py-0.5 rounded-full font-medium">
                        {{ count($shopifyPayload['variants'] ?? []) }} variant(s)
                    </span>
                    @if(!empty($shopifyPayload['options']))
                    <span class="text-xs bg-gray-100 text-gray-600 px-2 py-0.5 rounded-full">
                        {{ count($shopifyPayload['options']) }} option(s)
                    </span>
                    @endif
                    <span class="text-xs text-gray-400">
                        Sent as <code class="bg-gray-100 px-1 rounded">POST /products.json</code>
                        wrapped in <code class="bg-gray-100 px-1 rounded">{"product": ...}</code>
                    </span>
                </div>
                <pre class="bg-gray-50 border border-gray-200 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto whitespace-pre-wrap"
                     style="max-height:72vh">{{ json_encode($shopifyPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
            @else
                <div class="py-10 text-center text-gray-400 text-sm">
                    No payload generated.
                    Make sure <a href="{{ route('dashboard.mappings.index', 'product_field') }}" class="text-indigo-500 hover:underline">Product Field Mappings</a> are configured and run <code class="bg-gray-100 px-1 rounded">php artisan migrate</code> to seed defaults.
                </div>
            @endif
        </div>

    </div>
</div>
@endsection