@extends('dashboard.layout')
@section('title', 'Products')
@section('page-title', 'Products Listing')

@section('content')

{{-- Flash Messages --}}
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

{{-- ── Sync Direction Indicator ── --}}
<div class="mb-4 bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-xl p-4">
    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-gray-700">Sync Direction</h3>
            <p class="text-xs text-gray-500 mt-0.5">
                @if($syncMode === 'erp_to_ecom')
                    Products managed in <strong>Odoo</strong>, synced to <strong>{{ ucfirst($ecomDriver) }}</strong>
                @elseif($syncMode === 'ecom_to_erp')
                    Products managed in <strong>{{ ucfirst($ecomDriver) }}</strong>, synced to <strong>Odoo</strong>
                @else
                    Products managed in <strong>both systems</strong>
                @endif
            </p>
        </div>
        <div class="flex items-center gap-2">
            @if($syncMode === 'erp_to_ecom')
                <span class="px-3 py-1.5 bg-indigo-100 text-indigo-700 rounded-lg text-xs font-medium">Odoo → {{ ucfirst($ecomDriver) }}</span>
            @elseif($syncMode === 'ecom_to_erp')
                <span class="px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-lg text-xs font-medium">{{ ucfirst($ecomDriver) }} → Odoo</span>
            @else
                <span class="px-3 py-1.5 bg-purple-100 text-purple-700 rounded-lg text-xs font-medium">⟷ Bidirectional</span>
            @endif
        </div>
    </div>
</div>

{{-- ── Bidirectional Tabs ── --}}
@if($syncMode === 'bidirectional')
<div class="mb-4">
    <div class="border-b border-gray-200">
        <nav class="-mb-px flex space-x-8">
            <a href="?direction=erp_to_ecom&search={{ $search }}&status={{ $status }}"
               class="py-3 px-1 border-b-2 font-medium text-sm {{ ($direction ?? 'erp_to_ecom') === 'erp_to_ecom' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                Odoo Products
                @if(isset($stats['erp_to_ecom']))
                    <span class="ml-2 py-0.5 px-2 rounded-full text-xs {{ ($direction ?? 'erp_to_ecom') === 'erp_to_ecom' ? 'bg-indigo-100 text-indigo-600' : 'bg-gray-100 text-gray-600' }}">
                        {{ $stats['erp_to_ecom']['total'] ?? 0 }}
                    </span>
                @endif
            </a>
            <a href="?direction=ecom_to_erp&search={{ $search }}&status={{ $status }}"
               class="py-3 px-1 border-b-2 font-medium text-sm {{ ($direction ?? 'erp_to_ecom') === 'ecom_to_erp' ? 'border-emerald-500 text-emerald-600' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300' }}">
                {{ ucfirst($ecomDriver) }} Products
                @if(isset($stats['ecom_to_erp']))
                    <span class="ml-2 py-0.5 px-2 rounded-full text-xs {{ ($direction ?? 'erp_to_ecom') === 'ecom_to_erp' ? 'bg-emerald-100 text-emerald-600' : 'bg-gray-100 text-gray-600' }}">
                        {{ $stats['ecom_to_erp']['total'] ?? 0 }}
                    </span>
                @endif
            </a>
        </nav>
    </div>
</div>
@endif

{{-- ── Stats Bar ── --}}
<div class="grid grid-cols-4 gap-3 mb-4">
    @if($syncMode === 'erp_to_ecom' || ($syncMode === 'bidirectional' && ($direction ?? 'erp_to_ecom') === 'erp_to_ecom'))
        @php
        $displayStats = $syncMode === 'bidirectional' ? $stats['erp_to_ecom'] : $stats;
        $statCards = [
            ['label' => 'Total Products', 'value' => $displayStats['total'] ?? 0, 'color' => 'text-gray-700', 'bg' => 'bg-white'],
            ['label' => 'Sent', 'value' => $displayStats['sent'] ?? 0, 'color' => 'text-emerald-600', 'bg' => 'bg-emerald-50'],
            ['label' => 'Failed', 'value' => $displayStats['failed'] ?? 0, 'color' => 'text-red-600', 'bg' => 'bg-red-50'],
            ['label' => 'Pending', 'value' => $displayStats['pending'] ?? 0, 'color' => 'text-amber-600', 'bg' => 'bg-amber-50'],
        ];
        @endphp
    @else
        @php
        $displayStats = $syncMode === 'bidirectional' ? $stats['ecom_to_erp'] : $stats;
        $statCards = [
            ['label' => 'Total Products', 'value' => $displayStats['total'] ?? 0, 'color' => 'text-gray-700', 'bg' => 'bg-white'],
            ['label' => 'Synced', 'value' => $displayStats['success'] ?? 0, 'color' => 'text-emerald-600', 'bg' => 'bg-emerald-50'],
            ['label' => 'Failed', 'value' => $displayStats['failed'] ?? 0, 'color' => 'text-red-600', 'bg' => 'bg-red-50'],
            ['label' => 'Pending', 'value' => $displayStats['pending'] ?? 0, 'color' => 'text-amber-600', 'bg' => 'bg-amber-50'],
        ];
        @endphp
    @endif
    
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
        @if($syncMode === 'bidirectional')
            <input type="hidden" name="direction" value="{{ $direction ?? 'erp_to_ecom' }}">
        @endif
        
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="Name, SKU, ID..."
                   class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm w-64 focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        
        <div>
            <label class="block text-xs font-medium text-gray-500 mb-1">Status</label>
            <select name="status" class="border border-gray-300 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none bg-white">
                <option value="all" {{ $status === 'all' ? 'selected' : '' }}>All Statuses</option>
                @if($syncMode === 'erp_to_ecom' || ($syncMode === 'bidirectional' && ($direction ?? 'erp_to_ecom') === 'erp_to_ecom'))
                    <option value="pending" {{ $status === 'pending' ? 'selected' : '' }}>Pending</option>
                    <option value="sent" {{ $status === 'sent' ? 'selected' : '' }}>Sent</option>
                    <option value="failed" {{ $status === 'failed' ? 'selected' : '' }}>Failed</option>
                @else
                    <option value="success" {{ $status === 'success' ? 'selected' : '' }}>Synced</option>
                    <option value="failed" {{ $status === 'failed' ? 'selected' : '' }}>Failed</option>
                @endif
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

{{-- ── Action Buttons ── --}}
<div class="flex flex-wrap items-center justify-end gap-3 mb-5">

    {{-- Fetch from ERP --}}
    @if($syncMode === 'erp_to_ecom' || $syncMode === 'bidirectional')
    <form method="POST" action="{{ route('dashboard.products.fetch') }}">
        @csrf
        <button type="submit"
                onclick="return confirm('Fetch ALL products from Odoo now?')"
                class="inline-flex items-center gap-1.5 bg-indigo-700 hover:bg-indigo-800 text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
            </svg>
            Fetch from Odoo
        </button>
    </form>
    @endif

    {{-- Pull from Ecom --}}
    @if($syncMode === 'ecom_to_erp' || $syncMode === 'bidirectional')
    <form method="POST" action="{{ route('dashboard.products.pull') }}">
        @csrf
        <button type="submit"
                onclick="return confirm('Pull ALL products from {{ ucfirst($ecomDriver) }} now?')"
                class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
            </svg>
            Pull from {{ ucfirst($ecomDriver) }}
        </button>
    </form>
    @endif

    {{-- Push to Ecom --}}
    @if($syncMode === 'erp_to_ecom' || $syncMode === 'bidirectional')
    <form method="POST" action="{{ route('dashboard.products.post-all') }}">
        @csrf
        <button type="submit"
                onclick="return confirm('Push ALL products to {{ ucfirst($ecomDriver) }}?')"
                class="inline-flex items-center gap-1.5 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l4 4m0 0l4-4m-4 4V4"/>
            </svg>
            Push to {{ ucfirst($ecomDriver) }}
        </button>
    </form>
    @endif
    
</div>

{{-- ── Products Table ── --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 border-b border-gray-200">
                <tr>
                    @if($syncMode === 'erp_to_ecom' || ($syncMode === 'bidirectional' && ($direction ?? 'erp_to_ecom') === 'erp_to_ecom'))
                        {{-- ERP → Ecom columns --}}
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Odoo ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Product Name</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">SKU</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ ucfirst($ecomDriver) }} ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Last Synced</th>
                    @else
                        {{-- Ecom → ERP columns --}}
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">{{ ucfirst($ecomDriver) }} ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Handle</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Odoo ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold text-gray-600 uppercase tracking-wider">Last Synced</th>
                    @endif
                    <th class="px-4 py-3 text-right text-xs font-semibold text-gray-600 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($products as $product)
                <tr class="hover:bg-gray-50 transition">
                    
                    @if($syncMode === 'erp_to_ecom' || ($syncMode === 'bidirectional' && ($direction ?? 'erp_to_ecom') === 'erp_to_ecom'))
                        {{-- ERP → Ecom data --}}
                        <td class="px-4 py-3 text-gray-700 font-medium">#{{ $product->odoo_id }}</td>
                        <td class="px-4 py-3">
                            <div class="text-gray-900 font-medium">{{ Str::limit($product->name ?? '—', 40) }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-600 font-mono text-xs">{{ $product->default_code ?? '—' }}</td>
                        <td class="px-4 py-3 text-gray-600">
                            @if($product->shopify_product_id)
                                <span class="font-mono text-xs">{{ $product->shopify_product_id }}</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($product->shopify_status === 'sent')
                                <span class="px-2 py-1 bg-emerald-100 text-emerald-700 rounded-md text-xs font-medium">✓ Sent</span>
                            @elseif($product->shopify_status === 'failed')
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded-md text-xs font-medium">✗ Failed</span>
                            @else
                                <span class="px-2 py-1 bg-gray-100 text-gray-600 rounded-md text-xs font-medium">⏳ Pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs">
                            {{ $product->shopify_synced_at ? $product->shopify_synced_at->diffForHumans() : '—' }}
                        </td>
                        
                    @else
                        {{-- Ecom → ERP data --}}
                        <td class="px-4 py-3 text-gray-700 font-medium font-mono text-xs">{{ $product->ecom_id }}</td>
                        <td class="px-4 py-3">
                            <div class="text-gray-900 font-medium">{{ Str::limit($product->ecom_handle ?? '—', 40) }}</div>
                        </td>
                        <td class="px-4 py-3 text-gray-600">
                            @if($product->erp_id)
                                <span class="font-medium">#{{ $product->erp_id }}</span>
                            @else
                                <span class="text-gray-400">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3">
                            @if($product->latest_log_status === 'success')
                                <span class="px-2 py-1 bg-emerald-100 text-emerald-700 rounded-md text-xs font-medium">✓ Synced</span>
                            @elseif($product->latest_log_status === 'failed')
                                <span class="px-2 py-1 bg-red-100 text-red-700 rounded-md text-xs font-medium">✗ Failed</span>
                            @else
                                <span class="px-2 py-1 bg-gray-100 text-gray-600 rounded-md text-xs font-medium">⏳ Pending</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-gray-500 text-xs">
                            {{ $product->last_synced_at ? $product->last_synced_at->diffForHumans() : '—' }}
                        </td>
                    @endif
                    
                    <td class="px-4 py-3 text-right">
                        <div class="flex items-center justify-end gap-2">
                            {{-- View Button --}}
                            <a href="{{ route('dashboard.products.show', $product->odoo_id ?? $product->erp_id) }}" 
                               class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 border border-indigo-200 hover:border-indigo-400 bg-white hover:bg-indigo-50 px-2 py-1 rounded-lg transition">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                                </svg>
                                Info
                            </a>

                            {{-- Tools Dropdown (only for ERP → Ecom) --}}
                            @if($syncMode === 'erp_to_ecom' || ($syncMode === 'bidirectional' && ($direction ?? 'erp_to_ecom') === 'erp_to_ecom'))
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
                                        <button type="submit"
                                                class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 flex items-center gap-2 transition">
                                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l4 4m0 0l4-4m-4 4V4"/>
                                            </svg>
                                            Push to {{ ucfirst($ecomDriver) }}
                                        </button>
                                    </form>

                                    {{-- View on Shopify --}}
                                    @if($product->shopify_product_id)
                                    <div class="border-t border-gray-100 my-1"></div>
                                    <a href="https://admin.shopify.com/store/{{ str_replace('.myshopify.com', '', $shopifyStore) }}/products/{{ $product->shopify_product_id }}"
                                       target="_blank"
                                       class="w-full text-left px-4 py-2 text-xs text-gray-500 hover:bg-gray-50 hover:text-gray-700 flex items-center gap-2 transition">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                                        </svg>
                                        View on {{ ucfirst($ecomDriver) }} ↗
                                    </a>
                                    @endif
                                </div>
                            </div>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="7" class="px-4 py-16 text-center">
                        <svg class="w-10 h-10 text-gray-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                        </svg>
                        <p class="text-sm text-gray-400 font-medium">No products found</p>
                        <p class="text-xs text-gray-300 mt-1">
                            @if($syncMode === 'erp_to_ecom')
                                Click <strong>Fetch from Odoo</strong> to import products
                            @elseif($syncMode === 'ecom_to_erp')
                                Click <strong>Pull from {{ ucfirst($ecomDriver) }}</strong> to import products
                            @else
                                Use the buttons above to sync products
                            @endif
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