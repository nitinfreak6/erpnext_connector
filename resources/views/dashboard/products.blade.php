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
    <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1.707-5.293l-3-3a1 1 0 00-1.414 1.414l3 3a1 1 0 001.414 0l5-5a1 1 0 00-1.414-1.414L11 12.707z" clip-rule="evenodd"/></svg>
    {{ session('error') }}
</div>
@endif

{{-- ── Filters ── --}}
<div class="bg-white rounded-xl border border-gray-200 p-4 mb-4 shadow-sm">
    <form method="GET" class="flex flex-wrap items-end gap-3">
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="SKU, Odoo ID, Shopify ID…"
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
            <label class="block text-xs font-medium text-gray-500 mb-1">Per Page</label>
            <select name="per_page" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none bg-white">
                @foreach([10, 25, 50, 100] as $pp)
                <option value="{{ $pp }}" {{ $perPage === $pp ? 'selected' : '' }}>{{ $pp }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-indigo-700 transition">
            Filter
        </button>
        <a href="{{ route('dashboard.products') }}" class="text-sm text-gray-400 hover:text-gray-600 py-1.5">Reset</a>
    </form>
</div>

{{-- ── Action bar ── --}}
<div class="flex flex-wrap items-center justify-end gap-3 mb-5">

    {{-- Fetch Products: pull all from ERP → save JSON → no push --}}
    <form method="POST" action="{{ route('dashboard.products.fetch') }}">
        @csrf
        <button type="submit"
                onclick="return confirm('Fetch ALL products from ERP now? This runs immediately and may take a moment.')"
                class="inline-flex items-center gap-1.5 bg-indigo-700 hover:bg-indigo-800 text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Fetch Products
        </button>
    </form>

    {{-- Post Products: push all cached → Shopify / Amazon --}}
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
                        onclick="return confirm('Post all cached products to {{ $ch }}? This runs immediately.')"
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
        <span class="text-xs text-gray-400">{{ count($cachedIds) }} with cached JSON</span>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Channel</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">ERP ID</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Shopify ID</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Variant ID</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">ERP Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">SKU</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Cache</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Last Synced</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($products as $mapping)
                @php
                    $isCached     = in_array((int) $mapping->odoo_id, $cachedIds);
                    $isAmazon     = str_starts_with($mapping->entity_type, 'amazon');
                    $odooActive   = $cachedStatuses[(int) $mapping->odoo_id] ?? null;
                    $storeHandle  = str_replace('.myshopify.com', '', $shopifyStore);

                    $firstVariant = \App\Models\SyncMapping::where('entity_type', 'product_variant')
                        ->where('odoo_id', $mapping->odoo_id)
                        ->first();

                    $variantCount = $variantCounts[$mapping->shopify_id] ?? 0;
                @endphp
                <tr class="hover:bg-gray-50/70 transition-colors">

                    {{-- Channel --}}
                    <td class="px-4 py-3">
                        @if($isAmazon)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-amber-100 text-amber-800">Amazon</span>
                        @else
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-800">
                                {{ $shopifyStore ?: 'Shopify' }}
                            </span>
                        @endif
                    </td>

                    {{-- ERP ID --}}
                    <td class="px-4 py-3">
                        <span class="font-mono text-xs bg-gray-100 text-gray-700 px-2 py-0.5 rounded">#{{ $mapping->odoo_id }}</span>
                    </td>

                    {{-- Shopify ID --}}
                    <td class="px-4 py-3 font-mono text-xs">
                        @if($mapping->shopify_id && !$isAmazon)
                            <a href="https://admin.shopify.com/store/{{ $storeHandle }}/products/{{ $mapping->shopify_id }}"
                               target="_blank"
                               class="text-indigo-600 hover:underline inline-flex items-center gap-0.5">
                                {{ Str::limit($mapping->shopify_id, 10) }}
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            </a>
                        @elseif($mapping->shopify_id)
                            <span class="text-amber-700">{{ Str::limit($mapping->shopify_id, 10) }}</span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>

                    {{-- Variant ID --}}
                    <td class="px-4 py-3 font-mono text-xs text-gray-500">
                        @if($firstVariant)
                            {{ Str::limit($firstVariant->shopify_id, 10) }}
                            @if($variantCount > 1)
                                <span class="text-gray-400">+{{ $variantCount - 1 }}</span>
                            @endif
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>

                    {{-- ERP Status --}}
                    <td class="px-4 py-3">
                        @if($odooActive === true || $odooActive === 1)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">Active</span>
                        @elseif($odooActive === false || $odooActive === 0)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-600">Archived</span>
                        @elseif($isCached)
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500">Unknown</span>
                        @else
                            <span class="text-gray-300 text-xs">No cache</span>
                        @endif
                    </td>

                    {{-- SKU --}}
                    <td class="px-4 py-3 text-xs font-mono text-gray-700">
                        {{ $mapping->odoo_reference ?: '—' }}
                    </td>

                    {{-- Cache status --}}
                    <td class="px-4 py-3">
                        @if($isCached)
                            <span class="inline-flex items-center gap-1 text-xs text-emerald-600">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
                                Cached
                            </span>
                        @else
                            <span class="text-xs text-gray-300">—</span>
                        @endif
                    </td>

                    {{-- Last Synced --}}
                    <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">
                        {{ $mapping->last_synced_at?->format('Y-m-d H:i') ?? 'Never' }}
                    </td>

                    {{-- Actions --}}
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-1.5">

                            {{-- Info button — view cached Odoo JSON --}}
                            @if($isCached)
                            <a href="{{ route('dashboard.products.show', $mapping->odoo_id) }}"
                               title="View cached ERP data"
                               class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 border border-indigo-200 hover:border-indigo-400 bg-indigo-50 hover:bg-indigo-100 px-2 py-1 rounded-lg transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                Info
                            </a>
                            @endif

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

                                    {{-- Section: ERP --}}
                                    <div class="px-3 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">ERP</div>

                                    {{-- Fetch single from ERP --}}
                                    <form method="POST" action="{{ route('dashboard.products.fetch-single', $mapping->odoo_id) }}">
                                        @csrf
                                        <button type="submit"
                                                class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 flex items-center gap-2 transition">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
                                            </svg>
                                            Fetch from ERP
                                        </button>
                                    </form>

                                    <div class="border-t border-gray-100 my-1"></div>

                                    {{-- Section: Push --}}
                                    <div class="px-3 py-1 text-xs font-semibold text-gray-400 uppercase tracking-wider">Push</div>

                                    {{-- Post to Shopify --}}
                                    <form method="POST" action="{{ route('dashboard.products.post-single', $mapping->odoo_id) }}">
                                        @csrf
                                        <input type="hidden" name="channel" value="shopify">
                                        <button type="submit"
                                                @if(!$isCached) disabled @endif
                                                class="w-full text-left px-4 py-2 text-xs flex items-center gap-2 transition
                                                       {{ $isCached ? 'text-gray-700 hover:bg-emerald-50 hover:text-emerald-700' : 'text-gray-300 cursor-not-allowed' }}">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l4 4m0 0l4-4m-4 4V4"/>
                                            </svg>
                                            Post to Shopify
                                            @if(!$isCached)<span class="ml-auto text-gray-300 text-xs">no cache</span>@endif
                                        </button>
                                    </form>

                                    {{-- Post to Amazon --}}
                                    <form method="POST" action="{{ route('dashboard.products.post-single', $mapping->odoo_id) }}">
                                        @csrf
                                        <input type="hidden" name="channel" value="amazon">
                                        <button type="submit"
                                                @if(!$isCached) disabled @endif
                                                class="w-full text-left px-4 py-2 text-xs flex items-center gap-2 transition
                                                       {{ $isCached ? 'text-gray-700 hover:bg-amber-50 hover:text-amber-700' : 'text-gray-300 cursor-not-allowed' }}">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l4 4m0 0l4-4m-4 4V4"/>
                                            </svg>
                                            Post to Amazon
                                            @if(!$isCached)<span class="ml-auto text-gray-300 text-xs">no cache</span>@endif
                                        </button>
                                    </form>

                                    {{-- Post to Both --}}
                                    <form method="POST" action="{{ route('dashboard.products.post-single', $mapping->odoo_id) }}">
                                        @csrf
                                        <input type="hidden" name="channel" value="both">
                                        <button type="submit"
                                                @if(!$isCached) disabled @endif
                                                class="w-full text-left px-4 py-2 text-xs flex items-center gap-2 transition
                                                       {{ $isCached ? 'text-gray-700 hover:bg-purple-50 hover:text-purple-700' : 'text-gray-300 cursor-not-allowed' }}">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"/>
                                            </svg>
                                            Post to Both
                                            @if(!$isCached)<span class="ml-auto text-gray-300 text-xs">no cache</span>@endif
                                        </button>
                                    </form>

                                    {{-- View on Shopify --}}
                                    @if(!$isAmazon && $mapping->shopify_id)
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="https://admin.shopify.com/store/{{ $storeHandle }}/products/{{ $mapping->shopify_id }}"
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
                    <td colspan="9" class="px-4 py-16 text-center">
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
        <p class="text-xs text-gray-500">Showing {{ $products->firstItem() }}–{{ $products->lastItem() }} of {{ $products->total() }}</p>
        {{ $products->links() }}
    </div>
    @endif
</div>

@endsection