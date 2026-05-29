<!DOCTYPE html>
<html lang="en" class="h-full bg-gray-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Dashboard') — Connector</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        /* Tailwind CDN doesn't support @apply; keep minimal plain CSS here. */
        .badge { display: inline-flex; align-items: center; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; line-height: 1rem; }
    </style>
</head>
<body class="h-full" x-data="{ sidebarOpen: false }">

<div class="flex h-screen overflow-hidden">

    {{-- ── Sidebar ── --}}
    <aside class="flex flex-col w-64 bg-indigo-900 shrink-0">
        {{-- Brand --}}
        <div class="flex items-center gap-3 px-5 py-5 border-b border-indigo-700/50">
            <div class="w-8 h-8 bg-indigo-500 rounded-lg flex items-center justify-center shrink-0">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/>
                </svg>
            </div>
            <div class="overflow-hidden">
                <div class="text-white font-semibold text-sm leading-tight truncate">Connector</div>
                <div class="text-indigo-300 text-xs truncate">{{ $erpDisplayName }} · {{ $ecomDisplayName }}</div>
            </div>
        </div>

        {{-- Navigation --}}
        <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">

            {{-- Overview --}}
            <a href="{{ route('dashboard') }}"
               class="flex items-center gap-3 px-3 py-2 text-sm rounded-lg transition-colors {{ request()->routeIs('dashboard') ? 'bg-indigo-700 text-white font-medium' : 'text-indigo-100 hover:bg-indigo-700/60' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/>
                </svg>
                Overview
            </a>
			
			 {{-- Admin section --}}
            @if(auth()->user()->isAdmin())
            <div class="pt-3 pb-1">
                <p class="px-3 text-xs font-semibold text-indigo-400 uppercase tracking-wider">Admin</p>
            </div>

            <a href="{{ route('dashboard.settings') }}"
               class="flex items-center gap-3 px-3 py-2 text-sm rounded-lg transition-colors {{ request()->routeIs('dashboard.settings*') ? 'bg-indigo-700 text-white font-medium' : 'text-indigo-100 hover:bg-indigo-700/60' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                </svg>
                Global Settings
            </a>

            <a href="{{ route('dashboard.users.index') }}"
               class="flex items-center gap-3 px-3 py-2 text-sm rounded-lg transition-colors {{ request()->routeIs('dashboard.users*') ? 'bg-indigo-700 text-white font-medium' : 'text-indigo-100 hover:bg-indigo-700/60' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
                </svg>
                Users
            </a>
            @endif

            {{-- Sync section --}}
            <div class="pt-3 pb-1">
                <p class="px-3 text-xs font-semibold text-indigo-400 uppercase tracking-wider">Sync</p>
            </div>
			
			<a href="{{ route('dashboard.customers') }}" 
			   class="flex items-center px-3 py-2 text-sm font-medium {{ request()->routeIs('dashboard.customers*') ? 'bg-indigo-100 text-indigo-700' : 'text-gray-700 hover:bg-gray-100' }} rounded-lg">
				<svg class="w-5 h-5 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>
				</svg>
				Customers
			</a>

            @if(auth()->user()->hasPermission('view-products'))
           <a href="{{ route('dashboard.products') }}"
			   class="flex items-center gap-3 px-3 py-2 text-sm rounded-lg transition-colors
					  {{ request()->routeIs('dashboard.product-cache*') ? 'bg-indigo-700 text-white font-medium' : 'text-indigo-100 hover:bg-indigo-700/60' }}">
				<svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
					<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
						  d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
				</svg>
				Products
			</a>
            @endif

            @if(auth()->user()->hasPermission('view-orders'))
            <a href="{{ route('dashboard.orders') }}"
               class="flex items-center gap-3 px-3 py-2 text-sm rounded-lg transition-colors {{ request()->routeIs('dashboard.orders') ? 'bg-indigo-700 text-white font-medium' : 'text-indigo-100 hover:bg-indigo-700/60' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                </svg>
                Orders
            </a>
            @endif

            @if(auth()->user()->hasPermission('view-inventory'))
            <a href="{{ route('dashboard.inventory') }}"
               class="flex items-center gap-3 px-3 py-2 text-sm rounded-lg transition-colors {{ request()->routeIs('dashboard.inventory') ? 'bg-indigo-700 text-white font-medium' : 'text-indigo-100 hover:bg-indigo-700/60' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582 4 8 4m8-4c0-2.21-3.582 4-8 4"/>
                </svg>
                Inventory
            </a>
            @endif

            {{-- Logs section --}}
            <div class="pt-3 pb-1">
                <p class="px-3 text-xs font-semibold text-indigo-400 uppercase tracking-wider">Logs & Events</p>
            </div>

            @if(auth()->user()->hasPermission('view-logs'))
            <a href="{{ route('dashboard.logs') }}"
               class="flex items-center gap-3 px-3 py-2 text-sm rounded-lg transition-colors {{ request()->routeIs('dashboard.logs*') ? 'bg-indigo-700 text-white font-medium' : 'text-indigo-100 hover:bg-indigo-700/60' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                </svg>
                Sync Logs
            </a>
            @endif

            @if(auth()->user()->hasPermission('view-webhooks'))
            <a href="{{ route('dashboard.webhooks') }}"
               class="flex items-center gap-3 px-3 py-2 text-sm rounded-lg transition-colors {{ request()->routeIs('dashboard.webhooks') ? 'bg-indigo-700 text-white font-medium' : 'text-indigo-100 hover:bg-indigo-700/60' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                          d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                </svg>
                Webhooks
            </a>
            @endif
			
			{{-- Mappings section --}}
			@if(auth()->user()->hasPermission('manage-settings'))
			<div class="pt-3 pb-1">
				<p class="px-3 text-xs font-semibold text-indigo-400 uppercase tracking-wider">Mappings</p>
			</div>
			 
			<div x-data="{ mappingsOpen: {{ request()->routeIs('dashboard.mappings*') ? 'true' : 'false' }} }">
				{{-- Mappings toggle --}}
				<button @click="mappingsOpen = !mappingsOpen"
						class="w-full flex items-center justify-between gap-3 px-3 py-2 text-sm rounded-lg transition-colors
							   {{ request()->routeIs('dashboard.mappings*') ? 'bg-indigo-700 text-white font-medium' : 'text-indigo-100 hover:bg-indigo-700/60' }}">
					<div class="flex items-center gap-3">
						<svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
								  d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
						</svg>
						Mappings
					</div>
					<svg class="w-3.5 h-3.5 shrink-0 transition-transform" :class="mappingsOpen ? 'rotate-180' : ''"
						 fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
					</svg>
				</button>
			 
				{{-- Sub-items --}}
				<div x-show="mappingsOpen" x-cloak x-transition:enter="transition ease-out duration-100"
					 x-transition:enter-start="opacity-0 -translate-y-1"
					 x-transition:enter-end="opacity-100 translate-y-0"
					 class="mt-1 ml-3 pl-3 border-l border-indigo-700/50 space-y-0.5">
			 
					@php
					$mappingTypes = [
						'warehouse'        => ['label' => 'Warehouse',        'icon' => 'M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z'],
						'shipping'         => ['label' => 'Shipping',         'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
						'category'         => ['label' => 'Category',         'icon' => 'M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
						'pricelist'        => ['label' => 'Pricelist',        'icon' => 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z'],
						'payment'          => ['label' => 'Payment',          'icon' => 'M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z'],
						'channel'          => ['label' => 'Channel',          'icon' => 'M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z'],
						'sales_order_type' => ['label' => 'Order Type',       'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
						'sales_rep'        => ['label' => 'Sales Rep',        'icon' => 'M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z'],
						'product_size'     => ['label' => 'Product Size',     'icon' => 'M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4'],
						'tax'              => ['label' => 'Tax',              'icon' => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z'],
					];
					@endphp
			 
					@foreach($mappingTypes as $slug => $info)
					<a href="{{ route('dashboard.mappings.index', $slug) }}"
					   class="flex items-center gap-2.5 px-2 py-1.5 text-xs rounded-lg transition-colors
							  {{ request()->routeIs('dashboard.mappings.index') && request()->route('type') === $slug
								 ? 'bg-indigo-600 text-white font-medium'
								 : 'text-indigo-200 hover:bg-indigo-700/50 hover:text-white' }}">
						<svg class="w-3.5 h-3.5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $info['icon'] }}"/>
						</svg>
						{{ $info['label'] }}
					</a>
					@endforeach
				</div>
			</div>
			@endif
			
				{{-- Field Configuration collapsible --}}
			@if(auth()->user()->hasPermission('manage-settings'))
			<div class="pt-3 pb-1">
				<p class="px-3 text-xs font-semibold text-indigo-400 uppercase tracking-wider">Field Configuration</p>
			</div>

			@php
				$fieldConfigEntities = \App\Models\EntityDefinition::where('is_active', true)->orderBy('sort_order')->get();
				$fieldConfigActive   = request()->routeIs('dashboard.product-field-config*');
			@endphp
			<div x-data="{ fieldConfigOpen: {{ $fieldConfigActive ? 'true' : 'false' }} }">
				<button @click="fieldConfigOpen = !fieldConfigOpen"
						class="w-full flex items-center justify-between gap-3 px-3 py-2 text-sm rounded-lg transition-colors
							   {{ $fieldConfigActive ? 'bg-indigo-700 text-white font-medium' : 'text-indigo-100 hover:bg-indigo-700/60' }}">
					<div class="flex items-center gap-3">
						<svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
								  d="M9 17V7m0 10a2 2 0 01-2 2H5a2 2 0 01-2-2V7a2 2 0 012-2h2a2 2 0 012 2m0 10a2 2 0 002 2h2a2 2 0 002-2M9 7a2 2 0 012-2h2a2 2 0 012 2m0 10V7m0 10a2 2 0 002 2h2a2 2 0 002-2V7a2 2 0 00-2-2h-2a2 2 0 00-2 2"/>
						</svg>
						Field Config
					</div>
					<svg class="w-3.5 h-3.5 shrink-0 transition-transform" :class="fieldConfigOpen ? 'rotate-180' : ''"
						 fill="none" stroke="currentColor" viewBox="0 0 24 24">
						<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
					</svg>
				</button>

				{{-- Sub-items — driven from entity_definitions table, no hardcoding --}}
				<div x-show="fieldConfigOpen" x-cloak
					 x-transition:enter="transition ease-out duration-100"
					 x-transition:enter-start="opacity-0 -translate-y-1"
					 x-transition:enter-end="opacity-100 translate-y-0"
					 class="mt-1 ml-3 pl-3 border-l border-indigo-700/50 space-y-0.5">

					@foreach($fieldConfigEntities as $fcEntity)
					<a href="{{ route('dashboard.product-field-config.index', ['entity' => $fcEntity->entity_type]) }}"
					   class="flex items-center gap-2.5 px-2 py-1.5 text-xs rounded-lg transition-colors
							  {{ request()->routeIs('dashboard.product-field-config*') && request()->query('entity') === $fcEntity->entity_type
								 ? 'bg-indigo-600 text-white font-medium'
								 : 'text-indigo-200 hover:bg-indigo-700/50 hover:text-white' }}">
						<svg class="w-3 h-3 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
							<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
						</svg>
						{{ $fcEntity->label }}
					</a>
					@endforeach

				</div>
			</div>
			@endif

		</nav>

        {{-- User footer --}}
        <div class="border-t border-indigo-700/50 px-4 py-3">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 bg-indigo-600 rounded-full flex items-center justify-center text-white text-sm font-bold shrink-0">
                    {{ strtoupper(substr(auth()->user()->name, 0, 1)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-white text-sm font-medium truncate">{{ auth()->user()->name }}</div>
                    <div class="text-indigo-300 text-xs truncate">{{ auth()->user()->role }}</div>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" title="Logout"
                            class="text-indigo-300 hover:text-white transition">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
                        </svg>
                    </button>
                </form>
            </div>
        </div>
    </aside>

    {{-- ── Main content ── --}}
    <div class="flex-1 flex flex-col overflow-hidden">

        {{-- Top bar --}}
        <header class="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between shrink-0">
            <h1 class="text-lg font-semibold text-gray-800">@yield('page-title', 'Dashboard')</h1>
            <div class="flex items-center gap-3">
                {{-- Flash success/error --}}
                @if(session('success'))
                    <span class="text-sm text-green-600 bg-green-50 border border-green-200 px-3 py-1 rounded-full">
                        ✓ {{ session('success') }}
                    </span>
                @endif
                @if(session('error'))
                    <span class="text-sm text-red-600 bg-red-50 border border-red-200 px-3 py-1 rounded-full">
                        ✗ {{ session('error') }}
                    </span>
                @endif

                {{-- Manual sync trigger (manager+) --}}
                @if(auth()->user()->hasPermission('trigger-sync'))
                <div x-data="{ open: false }" class="relative">
                   
                    <div x-show="open" x-cloak @click.outside="open = false"
                         class="absolute right-0 top-10 w-52 bg-white border border-gray-200 rounded-xl shadow-lg z-50 py-1">
                        @foreach([
                            'products' => 'Shopify Products',
                            'inventory' => 'Shopify Inventory',
                            'orders' => 'Shopify Orders',
                            'customers' => 'Customers',
                            'amazon_products' => 'Amazon Products',
                            'amazon_orders' => 'Amazon Orders',
                            'amazon_inventory' => 'Amazon Inventory',
                        ] as $type => $label)
                        <form method="POST" action="{{ route('dashboard.sync.trigger') }}">
                            @csrf
                            <input type="hidden" name="type" value="{{ $type }}">
                            <button type="submit"
                                    class="w-full text-left px-4 py-2 text-sm text-gray-700 hover:bg-indigo-50 hover:text-indigo-700">
                                {{ $label }}
                            </button>
                        </form>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </header>

        {{-- Page content --}}
        <main class="flex-1 overflow-y-auto p-6">
            @yield('content')
        </main>
    </div>
</div>

@stack('scripts')
</body>
</html>