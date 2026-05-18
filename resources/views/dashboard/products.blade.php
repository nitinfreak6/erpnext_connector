@extends('dashboard.layout')
@section('title', 'Products')
@section('page-title', 'Products Listing')

@section('content')

{{-- Flash --}}
@if(session('success'))
<div class="mb-4 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
    <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
    {{ session('success') }}
</div>
@endif
@if(session('error'))
<div class="mb-4 bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
    <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
    {{ session('error') }}
</div>
@endif

{{-- ── Stats bar ── --}}
<div class="grid grid-cols-4 gap-3 mb-4">
    @php
    $statCards = [
        ['label' => 'Total Products',    'value' => $stats['total'],           'color' => 'text-gray-700',    'bg' => 'bg-white'],
        ['label' => 'Shopify Synced',    'value' => $stats['shopify_sent'],    'color' => 'text-emerald-600', 'bg' => 'bg-emerald-50'],
        ['label' => 'Shopify Failed',    'value' => $stats['shopify_failed'],  'color' => 'text-red-600',     'bg' => 'bg-red-50'],
        ['label' => 'Amazon Synced',     'value' => $stats['amazon_sent'],     'color' => 'text-amber-600',   'bg' => 'bg-amber-50'],
    ];
    @endphp
    @foreach($statCards as $card)
    <div class="{{ $card['bg'] }} border border-gray-200 rounded-xl px-4 py-3 shadow-sm">
        <div class="text-xs text-gray-500 font-medium">{{ $card['label'] }}</div>
        <div class="text-2xl font-bold {{ $card['color'] }} mt-0.5">{{ number_format($card['value']) }}</div>
    </div>
    @endforeach
</div>

{{-- ── Filters ── --}}
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 shadow-sm">
    <form method="GET" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="Name, SKU, ASIN, Shopify ID…"
                   class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-64 focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Channel</label>
            <select name="channel" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none bg-white">
                <option value="all"     {{ $channel === 'all'     ? 'selected' : '' }}>All Channels</option>
                <option value="shopify" {{ $channel === 'shopify' ? 'selected' : '' }}>Shopify</option>
                <option value="amazon"  {{ $channel === 'amazon'  ? 'selected' : '' }}>Amazon</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
            <select name="status" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none bg-white">
                <option value="all"     {{ $status === 'all'     ? 'selected' : '' }}>All Statuses</option>
                <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
                <option value="sent"    {{ $status === 'sent'    ? 'selected' : '' }}>Sent</option>
                <option value="failed"  {{ $status === 'failed'  ? 'selected' : '' }}>Failed</option>
                <option value="skipped" {{ $status === 'skipped' ? 'selected' : '' }}>Skipped</option>
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Per Page</label>
            <select name="per_page" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none bg-white">
                @foreach([10, 25, 50, 100] as $pp)
                <option value="{{ $pp }}" {{ $perPage === $pp ? 'selected' : '' }}>{{ $pp }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-indigo-700 transition">Filter</button>
        <a href="{{ route('dashboard.products') }}" class="text-sm text-gray-400 hover:text-gray-600 py-1.5">Reset</a>
    </form>
</div>

{{-- ── Action bar ── --}}
<div class="flex flex-wrap items-center justify-end gap-3 mb-5">

    {{-- Fetch Products --}}
    <form method="POST" action="{{ route('dashboard.products.fetch') }}">
        @csrf
        <button type="submit"
                onclick="return confirm('Fetch ALL products from Odoo now?')"
                class="inline-flex items-center gap-1.5 bg-indigo-700 hover:bg-indigo-800 text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Fetch Products
        </button>
    </form>

    {{-- Post Products dropdown --}}
    <div x-data="{ open: false }" class="relative">
        <button @click="open = !open"
                class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l4 4m0 0l4-4m-4 4V4"/>
            </svg>
            Post Products
            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>
        <div x-show="open" x-cloak @click.outside="open = false"
             class="absolute right-0 top-10 bg-white border border-gray-200 rounded-xl shadow-lg z-50 py-1 w-48">
            @foreach(['both' => '🔄 Shopify + Amazon', 'shopify' => '🛍 Shopify Only', 'amazon' => '📦 Amazon Only'] as $ch => $label)
            <form method="POST" action="{{ route('dashboard.products.post-all') }}">
                @csrf
                <input type="hidden" name="channel" value="{{ $ch }}">
                <button type="submit"
                        onclick="return confirm('Post all products to {{ $ch }}?')"
                        class="w-full text-left px-4 py-2.5 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">
                    {{ $label }}
                </button>
            </form>
            @endforeach
        </div>
    </div>
</div>

{{-- ── Table ── --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between bg-gray-50">
        <span class="text-sm font-semibold text-gray-700">
            {{ $products->total() }} product{{ $products->total() !== 1 ? 's' : '' }}
        </span>
        <span class="text-xs text-gray-400">
            Shopify: {{ $stats['shopify_sent'] }} synced · Amazon: {{ $stats['amazon_sent'] }} synced
        </span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Product</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">SKU</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Price</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Variants</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Shopify</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Amazon</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Fetched</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($products as $product)
                @php
                    $storeHandle = str_replace('.myshopify.com', '', $shopifyStore);
                @endphp
                <tr class="hover:bg-gray-50/70 transition-colors">

                    {{-- Product name + Odoo ID --}}
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-900 text-sm leading-tight">
                            {{ Str::limit($product->name, 40) }}
                        </div>
                        <div class="flex items-center gap-2 mt-0.5">
                            <span class="font-mono text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">
                                #{{ $product->odoo_id }}
                            </span>
                            @if($product->category)
                            <span class="text-xs text-gray-400">{{ $product->category }}</span>
                            @endif
                            @if(!$product->is_active)
                            <span class="badge bg-red-100 text-red-600">Archived</span>
                            @endif
                        </div>
                    </td>

                    {{-- SKU --}}
                    <td class="px-4 py-3">
                        @if($product->default_code)
                        <span class="font-mono text-xs bg-gray-100 text-gray-700 px-2 py-0.5 rounded">
                            {{ $product->default_code }}
                        </span>
                        @else
                        <span class="text-gray-300 text-xs">—</span>
                        @endif
                    </td>

                    {{-- Price --}}
                    <td class="px-4 py-3 text-sm text-gray-700">
                        @if($product->price !== null)
                            ${{ number_format($product->price, 2) }}
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>

                    {{-- Variant count --}}
                    <td class="px-4 py-3 text-center">
                        <span class="text-xs {{ $product->variant_count > 1 ? 'bg-indigo-100 text-indigo-700 font-semibold px-2 py-0.5 rounded-full' : 'text-gray-400' }}">
                            {{ $product->variant_count ?: '—' }}
                        </span>
                    </td>

                    {{-- Shopify status --}}
                    <td class="px-4 py-3">
                        <span class="badge {{ $product->statusBadgeClass('shopify') }}">
                            {{ ucfirst($product->shopify_status) }}
                        </span>
                        @if($product->shopify_product_id)
                        <div class="mt-0.5">
                            <a href="https://admin.shopify.com/store/{{ $storeHandle }}/products/{{ $product->shopify_product_id }}"
                               target="_blank"
                               class="text-xs text-indigo-500 hover:underline font-mono">
                                {{ Str::limit($product->shopify_product_id, 12) }} ↗
                            </a>
                        </div>
                        @endif
                        @if($product->shopify_message)
                        <div class="text-xs text-red-500 mt-0.5 max-w-[160px] truncate" title="{{ $product->shopify_message }}">
                            {{ Str::limit($product->shopify_message, 30) }}
                        </div>
                        @endif
                    </td>

                    {{-- Amazon status --}}
                    <td class="px-4 py-3">
                        <span class="badge {{ $product->statusBadgeClass('amazon') }}">
                            {{ ucfirst($product->amazon_status) }}
                        </span>
                        @if($product->amazon_asin)
                        <div class="font-mono text-xs text-amber-600 mt-0.5">{{ $product->amazon_asin }}</div>
                        @endif
                        @if($product->amazon_message)
                        <div class="text-xs text-red-500 mt-0.5 max-w-[160px] truncate" title="{{ $product->amazon_message }}">
                            {{ Str::limit($product->amazon_message, 30) }}
                        </div>
                        @endif
                    </td>

                    {{-- Fetched at --}}
                    <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">
                        {{ $product->fetched_at?->diffForHumans() ?? 'Never' }}
                    </td>

                    {{-- Actions --}}
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1.5">

                            {{-- Info / Detail --}}
                            <a href="{{ route('dashboard.products.show', $product->odoo_id) }}"
                               title="View product detail"
                               class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 border border-indigo-200 hover:border-indigo-400 bg-indigo-50 hover:bg-indigo-100 px-2 py-1 rounded-lg transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                Info
                            </a>

                            {{-- Tools dropdown --}}
                            <div class="relative" x-data="{ open: false }">
                                <button @click="open = !open"
                                        class="inline-flex items-center gap-1 text-xs text-gray-600 hover:text-gray-800 border border-gray-200 hover:border-gray-400 bg-white hover:bg-gray-50 px-2 py-1 rounded-lg transition">
                                    Tools
                                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                    </svg>
                                </button>

                                <div x-show="open" x-cloak @click.outside="open = false"
                                     class="absolute right-0 z-30 mt-1 w-52 bg-white border border-gray-200 rounded-xl shadow-xl py-1.5">

                                    {{-- Fetch from Odoo --}}
                                    <div class="px-3 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Odoo</div>
                                    <form method="POST" action="{{ route('dashboard.products.fetch-single', $product->odoo_id) }}">
                                        @csrf
                                        <button type="submit"
                                                class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 flex items-center gap-2 transition">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                            </svg>
                                            Fetch from Odoo
                                        </button>
                                    </form>

                                    <div class="border-t border-gray-100 my-1"></div>
                                    <div class="px-3 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Push</div>

                                    {{-- Post to Shopify --}}
                                    <form method="POST" action="{{ route('dashboard.products.post-single', $product->odoo_id) }}">
                                        @csrf
                                        <input type="hidden" name="channel" value="shopify">
                                        <button type="submit"
                                                class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 flex items-center gap-2 transition">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l4 4m0 0l4-4m-4 4V4"/>
                                            </svg>
                                            Post to Shopify
                                        </button>
                                    </form>

                                    {{-- Post to Amazon --}}
                                    <form method="POST" action="{{ route('dashboard.products.post-single', $product->odoo_id) }}">
                                        @csrf
                                        <input type="hidden" name="channel" value="amazon">
                                        <button type="submit"
                                                class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-amber-50 hover:text-amber-700 flex items-center gap-2 transition">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l4 4m0 0l4-4m-4 4V4"/>
                                            </svg>
                                            Post to Amazon
                                        </button>
                                    </form>

                                    {{-- Post to Both --}}
                                    <form method="POST" action="{{ route('dashboard.products.post-single', $product->odoo_id) }}">
                                        @csrf
                                        <input type="hidden" name="channel" value="both">
                                        <button type="submit"
                                                class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-purple-50 hover:text-purple-700 flex items-center gap-2 transition">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                            </svg>
                                            Post to Both
                                        </button>
                                    </form>

                                    {{-- View on Shopify --}}
                                    @if($product->shopify_product_id)
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="https://admin.shopify.com/store/{{ $storeHandle }}/products/{{ $product->shopify_product_id }}"
                                       target="_blank"
                                       class="w-full text-left px-4 py-2 text-xs text-gray-500 hover:bg-gray-50 hover:text-gray-700 flex items-center gap-2 transition">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                        </svg>
                                        View on Shopify ↗
                                    </a>
                                    @endif
                                </div>
                            </div>

                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="8" class="px-4 py-16 text-center">
                        <svg class="w-10 h-10 text-gray-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        <p class="text-sm text-gray-400 font-medium">No products found</p>
                        <p class="text-xs text-gray-300 mt-1">
                            Click <strong>Fetch Products</strong> above or run
                            <code class="bg-gray-100 px-1 rounded">php artisan sync:products --full</code>
                        </p>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($products->hasPages())
    <div class="px-5 py-3 border-t border-gray-100 flex items-center justify-between bg-gray-50">
        <p class="text-xs text-gray-500">
            Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }}
        </p>
        {{ $products->links() }}
    </div>
    @endif
</div>

@endsection