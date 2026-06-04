@extends('dashboard.layout')
@section('title', 'Orders')
@section('page-title', 'Orders Sync')

@section('content')

{{-- Flash Messages --}}
@foreach(['success' => 'emerald', 'error' => 'red', 'info' => 'blue', 'warning' => 'amber'] as $type => $color)
@if(session($type))
<div class="mb-4 bg-{{ $color }}-50 border border-{{ $color }}-200 text-{{ $color }}-700 px-4 py-3 rounded-lg text-sm flex items-center gap-2">
    <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/></svg>
    {!! session($type) !!}
</div>
@endif
@endforeach

{{-- Sync Direction --}}
<div class="mb-4 bg-gradient-to-r from-emerald-50 to-teal-50 border border-emerald-200 rounded-xl p-4">
    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-sm font-semibold text-gray-700">Sync Direction</h3>
            <p class="text-xs text-gray-500 mt-0.5">
                @if($syncMode === 'erp_to_ecom')
                    Orders created in <strong>{{ $erpDisplayName }}</strong>, fulfillments synced to <strong>{{ $ecomDisplayName }}</strong>
                @elseif($syncMode === 'ecom_to_erp')
                    Orders imported from <strong>{{ $ecomDisplayName }}</strong> to <strong>{{ $erpDisplayName }}</strong>
                @else
                    Orders flow in <strong>both directions</strong>
                @endif
            </p>
        </div>
        <div>
            @if($syncMode === 'erp_to_ecom')
                <span class="px-3 py-1.5 bg-indigo-100 text-indigo-700 rounded-lg text-xs font-medium">{{ $erpDisplayName }} → {{ $ecomDisplayName }}</span>
            @elseif($syncMode === 'ecom_to_erp')
                <span class="px-3 py-1.5 bg-emerald-100 text-emerald-700 rounded-lg text-xs font-medium">{{ $ecomDisplayName }} → {{ $erpDisplayName }}</span>
            @else
                <span class="px-3 py-1.5 bg-purple-100 text-purple-700 rounded-lg text-xs font-medium">⟷ Bidirectional</span>
            @endif
        </div>
    </div>
</div>

{{-- Stats --}}
<div class="grid grid-cols-4 gap-3 mb-4">
    @foreach([
        ['label' => 'Total Orders',                 'value' => $stats['total'] ?? 0,        'color' => 'text-gray-700',    'bg' => 'bg-white'],
        ['label' => $ecomDisplayName . ' Orders',   'value' => $stats['ecom_total'] ?? 0,   'color' => 'text-emerald-600', 'bg' => 'bg-emerald-50'],
        ['label' => 'Amazon Orders',                'value' => $stats['amazon_total'] ?? 0, 'color' => 'text-amber-600',   'bg' => 'bg-amber-50'],
        ['label' => 'Synced Today',                 'value' => $stats['today'] ?? 0,        'color' => 'text-blue-600',    'bg' => 'bg-blue-50'],
    ] as $card)
    <div class="{{ $card['bg'] }} border border-gray-200 rounded-xl px-4 py-3 shadow-sm">
        <div class="text-xs text-gray-500 font-medium">{{ $card['label'] }}</div>
        <div class="text-2xl font-bold {{ $card['color'] }} mt-0.5">{{ number_format($card['value']) }}</div>
    </div>
    @endforeach
</div>

{{-- Action Buttons --}}
<div class="flex flex-wrap gap-2 mb-4">

    {{-- GROUP 1: Order sync — direction-aware --}}
    <div class="flex gap-2 items-center">
        <span class="text-xs text-gray-400 font-medium uppercase tracking-wide mr-1">Orders</span>

        @if($syncMode === 'ecom_to_erp' || $syncMode === 'bidirectional')
        {{-- Shopify → Odoo --}}
        <form method="POST" action="{{ route('dashboard.orders.pull') }}">
            @csrf
            <button type="submit" class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Fetch from {{ $ecomDisplayName }}
            </button>
        </form>
        <form method="POST" action="{{ route('dashboard.orders.post-sales') }}">
            @csrf
            <button type="submit" class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l4 4m0 0l4-4m-4 4V4"/></svg>
                Post to {{ $erpDisplayName }}
            </button>
        </form>
        @endif

        @if($syncMode === 'erp_to_ecom' || $syncMode === 'bidirectional')
        {{-- Odoo → Shopify --}}
        <form method="POST" action="{{ route('dashboard.orders.fetch') }}">
            @csrf
            <button type="submit" class="inline-flex items-center gap-1.5 bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
                Fetch from {{ $erpDisplayName }}
            </button>
        </form>
        <form method="POST" action="{{ route('dashboard.orders.post-sales') }}">
            @csrf
            <button type="submit" class="inline-flex items-center gap-1.5 bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l4 4m0 0l4-4m-4 4V4"/></svg>
                Post to {{ $ecomDisplayName }}
            </button>
        </form>
        @endif
    </div>

    <div class="w-px bg-gray-200 self-stretch mx-1"></div>

    {{-- GROUP 2: Dispatch — always Odoo → Shopify regardless of order sync mode --}}
    <div class="flex gap-2 items-center">
        <span class="text-xs text-gray-400 font-medium uppercase tracking-wide mr-1">Dispatch</span>
        <form method="POST" action="{{ route('dashboard.orders.fetch-dispatch') }}">
            @csrf
            <button type="submit" class="inline-flex items-center gap-1.5 bg-purple-600 hover:bg-purple-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                Fetch Dispatch
            </button>
        </form>
        <form method="POST" action="{{ route('dashboard.orders.post-dispatch') }}">
            @csrf
            <button type="submit" class="inline-flex items-center gap-1.5 bg-teal-600 hover:bg-teal-700 text-white px-4 py-2 rounded-lg text-sm font-medium">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                Post Dispatch
            </button>
        </form>
    </div>

</div>

{{-- Filters --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ $search }}"
                   placeholder="Order ID, reference…"
                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm w-64 focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Channel</label>
            <select name="channel" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="all"     {{ $channel === 'all'     ? 'selected' : '' }}>All</option>
                <option value="shopify" {{ $channel === 'shopify' ? 'selected' : '' }}>{{ $ecomDisplayName }}</option>
                <option value="amazon"  {{ $channel === 'amazon'  ? 'selected' : '' }}>Amazon</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Per Page</label>
            <select name="per_page" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm outline-none border-gray-200">
                @foreach([25, 50, 100] as $n)
                <option value="{{ $n }}" {{ $perPage == $n ? 'selected' : '' }}>{{ $n }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-indigo-700">Filter</button>
        <a href="{{ route('dashboard.orders') }}" class="text-sm text-gray-500 py-1.5">Reset</a>
    </form>
</div>

{{-- Table --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden mb-4">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-medium text-gray-700">{{ $orders->total() }} orders</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left font-medium">Channel</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $erpDisplayName }} ID</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $ecomDisplayName }} ID</th>
                    <th class="px-4 py-3 text-left font-medium">Reference</th>
                    <th class="px-4 py-3 text-left font-medium">Sync Status</th>
                    <th class="px-4 py-3 text-left font-medium">Status</th>
                    <th class="px-4 py-3 text-left font-medium">Dispatch Status</th>
                    <th class="px-4 py-3 text-left font-medium">Last Synced</th>
                    <th class="px-4 py-3 text-right font-medium">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($orders as $mapping)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        @if(str_starts_with($mapping->entity_type, 'amazon'))
                            <span class="badge bg-amber-100 text-amber-800 text-xs">Amazon</span>
                        @else
                            <span class="badge bg-emerald-100 text-emerald-800 text-xs">{{ $ecomDisplayName }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-mono text-xs">
                        @if($mapping->erp_id)
                            <span class="bg-gray-100 px-1.5 py-0.5 rounded text-gray-700">#{{ $mapping->erp_id }}</span>
                        @else
                            <span class="text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-mono text-xs text-gray-600">
                        {{ $mapping->ecom_id ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-gray-700 font-medium text-xs">
                        {{ $mapping->ecom_handle ?? '—' }}
                    </td>

                    {{-- Sync status --}}
                    <td class="px-4 py-3">
                        @if($mapping->latest_log)
                            @php $s = $mapping->latest_log->status; $c = ['success'=>'emerald','failed'=>'red','processing'=>'blue','pending'=>'amber'][$s] ?? 'gray'; @endphp
                            <span class="badge bg-{{ $c }}-100 text-{{ $c }}-700 text-xs">{{ ucfirst($s) }}</span>
                        @else
                            <span class="badge bg-gray-100 text-gray-500 text-xs">Synced</span>
                        @endif
                    </td>

                    {{-- Order status --}}
                    <td class="px-4 py-3">
                        @php
                            $statuses = [
                                'pending' => ['label' => 'Pending', 'color' => 'amber'],
                                'posted'  => ['label' => 'Sent',    'color' => 'emerald'],
                            ];
                            $status = $statuses[$mapping->ecom_status] ?? ['label' => 'Unknown', 'color' => 'gray'];
                        @endphp
                        <span class="badge bg-{{ $status['color'] }}-100 text-{{ $status['color'] }}-700 text-xs">
                            {{ $status['label'] }}
                        </span>
                    </td>

                    {{-- Dispatch status --}}
                    <td class="px-4 py-3">
                        @if($mapping->dispatch_log)
                            @php $s = $mapping->dispatch_log->status; $c = ['success'=>'emerald','failed'=>'red','processing'=>'blue','pending'=>'amber'][$s] ?? 'gray'; @endphp
                            <span class="badge bg-{{ $c }}-100 text-{{ $c }}-700 text-xs">{{ ucfirst($s) }}</span>
                        @else
                            <span class="badge bg-gray-100 text-gray-400 text-xs">Not dispatched</span>
                        @endif
                    </td>

                    <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">
                        {{ $mapping->last_synced_at?->diffForHumans() ?? 'Never' }}
                    </td>

                    {{-- Tools dropdown --}}
                    <td class="px-4 py-3 text-right">
                        <div class="relative inline-block" x-data="{ open: false }">
                            <button @click="open = !open"
                                    class="inline-flex items-center gap-1 text-xs border border-gray-300 text-gray-600 hover:bg-gray-50 px-2.5 py-1.5 rounded-lg transition">
                                Tools
                                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                            </button>

                            <div x-show="open" @click.away="open = false" x-cloak
                                 class="absolute right-0 mt-1 w-52 bg-white rounded-lg shadow-lg border border-gray-100 z-20 py-1">

                                {{-- Sales Info — always visible, uses ecom_id or erp_id --}}
                                @if($mapping->erp_id)
                                <a href="{{ route('dashboard.orders.sales-info', $mapping->erp_id) }}"
                                   class="flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    Sales Info
                                </a>
                                
                                @endif

                                {{-- Dispatch Info — only once order is in Odoo --}}
                                @if($mapping->erp_id)
                                <a href="{{ route('dashboard.orders.dispatch-info', $mapping->erp_id) }}"
                                   class="flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-purple-50 hover:text-purple-700">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                                    Dispatch Info
                                </a>
                                @endif
                                <div class="border-t border-gray-100 my-1"></div>

                                {{-- Post Sale --}}
                                {{-- ecom_to_erp: push Shopify order → Odoo (show when pending or not yet in Odoo) --}}
                                @if(($syncMode === 'ecom_to_erp' || $syncMode === 'bidirectional') && $mapping->ecom_id && !$mapping->erp_id)
                                <form method="POST" action="{{ route('dashboard.orders.post-single', $mapping->ecom_id) }}">
                                    @csrf
                                    <button type="submit" class="w-full flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l4 4m0 0l4-4m-4 4V4"/></svg>
                                        Post to {{ $erpDisplayName }}
                                    </button>
                                </form>
                                @endif

                                {{-- erp_to_ecom: push Odoo order → Shopify --}}
                                @if(($syncMode === 'erp_to_ecom' || $syncMode === 'bidirectional') && $mapping->erp_id && !$mapping->ecom_id)
                                <form method="POST" action="{{ route('dashboard.orders.push', $mapping->erp_id) }}">
                                    @csrf
                                    <button type="submit" class="w-full flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l4 4m0 0l4-4m-4 4V4"/></svg>
                                        Post to {{ $ecomDisplayName }}
                                    </button>
                                </form>
                                @endif

                                {{-- Dispatch section — always Odoo → Shopify --}}
                                @if($mapping->erp_id)
                                <div class="border-t border-gray-100 my-1"></div>
                                <form method="POST" action="{{ route('dashboard.orders.fetch-dispatch-single', $mapping->erp_id) }}">
                                    @csrf
                                    <button type="submit" class="w-full flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-purple-50 hover:text-purple-700">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                                        Fetch Dispatch
                                    </button>
                                </form>
                                <form method="POST" action="{{ route('dashboard.orders.post-dispatch-single', $mapping->erp_id) }}">
                                    @csrf
                                    <button type="submit" class="w-full flex items-center gap-2 px-3 py-2 text-xs text-gray-700 hover:bg-teal-50 hover:text-teal-700">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg>
                                        Post Dispatch
                                    </button>
                                </form>
                                @else
                                {{-- Order not in Odoo yet — Dispatch not available --}}
                                <div class="border-t border-gray-100 my-1"></div>
                                <div class="flex items-center gap-2 px-3 py-2 text-xs text-amber-500">
                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                    Post to {{ $erpDisplayName }} first
                                </div>
                                @endif

                            </div>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="9" class="px-4 py-12 text-center text-gray-400">
                        No order mappings yet. Use <strong>Fetch Sales</strong> or <strong>Post Sales</strong> to sync orders.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100">{{ $orders->links() }}</div>
</div>



@endsection