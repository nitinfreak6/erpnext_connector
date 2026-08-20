@if(auth()->user()->can('trigger-sync'))
@php
    $ss = app(\App\Services\SettingsService::class);
    $syncTriggers = array_filter([
        'products'         => $ss->isProductSyncEnabled(),
        'inventory'        => $ss->isInventorySyncEnabled(),
        'orders'           => $ss->isSalesOrderSyncEnabled(),
        'customers'        => $ss->isCustomerSyncEnabled(),
        'amazon_products'  => $ss->isAmazonChannelEnabled() && $ss->isProductSyncEnabled(),
        'amazon_orders'    => $ss->isAmazonChannelEnabled() && $ss->isSalesOrderSyncEnabled(),
        'amazon_inventory' => $ss->isAmazonChannelEnabled() && $ss->isInventorySyncEnabled(),
    ]);
    $triggerLabels = [
        'products'         => ['label' => 'Shopify Products',  'color' => '#6366f1'],
        'inventory'        => ['label' => 'Shopify Inventory', 'color' => '#6366f1'],
        'orders'           => ['label' => 'Shopify Orders',    'color' => '#6366f1'],
        'customers'        => ['label' => 'Customers',         'color' => '#6366f1'],
        'amazon_products'  => ['label' => 'Amazon Products',   'color' => '#d97706'],
        'amazon_orders'    => ['label' => 'Amazon Orders',     'color' => '#d97706'],
        'amazon_inventory' => ['label' => 'Amazon Inventory',  'color' => '#d97706'],
    ];
@endphp
@if(!empty($syncTriggers))
<div class="settings-card">
    <div class="settings-header"
         style="background:linear-gradient(135deg,#475569,#334155)"
         onclick="toggleSection('section-sync-triggers', this)">
        <span class="icon">+</span>
        Sync Triggers
        <svg class="chevron w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
        </svg>
    </div>
    <div class="settings-divider"></div>
    <div id="section-sync-triggers" class="settings-body" style="display:none">
        <div style="padding:4px 24px 8px;display:grid;grid-template-columns:repeat(4,1fr);gap:10px">
            @foreach($syncTriggers as $type => $_enabled)
            @php $info = $triggerLabels[$type]; @endphp
            <form method="POST" action="{{ route('dashboard.sync.trigger') }}">
                @csrf
                <input type="hidden" name="type" value="{{ $type }}">
                <button type="submit"
                        style="width:100%;padding:8px 10px;border:1.5px solid {{ $info['color'] }}30;border-radius:7px;background:{{ $info['color'] }}10;color:{{ $info['color'] }};font-size:12px;font-weight:600;cursor:pointer;transition:background 0.15s"
                        onmouseover="this.style.background='{{ $info['color'] }}20'"
                        onmouseout="this.style.background='{{ $info['color'] }}10'">
                    ↺ {{ $info['label'] }}
                </button>
            </form>
            @endforeach
        </div>
    </div>
</div>
@endif
@endif
