@php
    /** @var \App\Services\SettingsService $ss */
    $ss        = app(\App\Services\SettingsService::class);
    $erpLabel  = $ss->erpDisplayName();
    $ecomLabel = $ss->ecomDisplayName();
    $productMode   = $ss->productSyncMode();
    $inventoryMode = $ss->inventorySyncMode();
    $customerMode  = $ss->customerSyncMode();
    $salesMode     = $ss->salesOrderSyncMode();
@endphp

<div class="dir-grid">

    <div class="dir-card">
        <div class="dir-pill"><span>−</span> Product Settings</div>
        <div class="dir-body">
            <div class="dir-row" x-data="{ on: {{ $ss->isProductSyncEnabled() ? 'true' : 'false' }} }">
                <div class="dir-label">Enable Product</div>
                <div class="toggle-wrap" style="padding-top:0">
                    <div class="toggle-track" :class="on?'on':''" @click="on=!on"><div class="toggle-thumb"></div></div>
                    <span class="toggle-label" x-text="on?'On':'Off'" :style="on?'color:#f97316;font-weight:700':''"></span>
                    <template x-if="on"><input type="hidden" name="product_sync_enabled" value="1"></template>
                    <template x-if="!on"><input type="hidden" name="product_sync_enabled" value="0"></template>
                </div>
            </div>
            <div class="dir-row" style="flex-direction:column;align-items:flex-start;gap:10px;" x-data="{ mode: '{{ $productMode }}' }">
                <div class="dir-label">Sync Direction</div>
                <input type="hidden" name="product_sync_mode" :value="mode">
                <div class="sync-mode-group">
                    <button type="button" :class="mode==='erp_to_ecom' ? 'smode-btn active' : 'smode-btn'" @click="mode='erp_to_ecom'">
                        <span class="smode-arrow">→</span> {{ $erpLabel }} → {{ $ecomLabel }}
                    </button>
                    <button type="button" :class="mode==='ecom_to_erp' ? 'smode-btn active' : 'smode-btn'" @click="mode='ecom_to_erp'">
                        <span class="smode-arrow">→</span> {{ $ecomLabel }} → {{ $erpLabel }}
                    </button>
                </div>
                <div class="flow-diagram" x-show="mode==='erp_to_ecom'">
                    <span class="flow-node">{{ $erpLabel }}</span><span class="flow-arrow">→</span><span class="flow-node">{{ $ecomLabel }}</span>
                </div>
                <div class="flow-diagram" x-show="mode==='ecom_to_erp'">
                    <span class="flow-node">{{ $ecomLabel }}</span><span class="flow-arrow">→</span><span class="flow-node">{{ $erpLabel }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="dir-card">
        <div class="dir-pill"><span>−</span> Inventory Settings</div>
        <div class="dir-body">
            <div class="dir-row" x-data="{ on: {{ $ss->isInventorySyncEnabled() ? 'true' : 'false' }} }">
                <div class="dir-label">Enable Inventory</div>
                <div class="toggle-wrap" style="padding-top:0">
                    <div class="toggle-track" :class="on?'on':''" @click="on=!on"><div class="toggle-thumb"></div></div>
                    <span class="toggle-label" x-text="on?'On':'Off'" :style="on?'color:#f97316;font-weight:700':''"></span>
                    <template x-if="on"><input type="hidden" name="inventory_sync_enabled" value="1"></template>
                    <template x-if="!on"><input type="hidden" name="inventory_sync_enabled" value="0"></template>
                </div>
            </div>
            <div class="dir-row" style="flex-direction:column;align-items:flex-start;gap:10px;" x-data="{ mode: '{{ $inventoryMode }}' }">
                <div class="dir-label">Inventory Sync Direction</div>
                <input type="hidden" name="inventory_sync_mode" :value="mode">
                <div class="sync-mode-group">
                    <button type="button" :class="mode==='erp_to_ecom' ? 'smode-btn active' : 'smode-btn'" @click="mode='erp_to_ecom'">
                        <span class="smode-arrow">→</span> {{ $erpLabel }} → {{ $ecomLabel }}
                    </button>
                    <button type="button" :class="mode==='ecom_to_erp' ? 'smode-btn active' : 'smode-btn'" @click="mode='ecom_to_erp'">
                        <span class="smode-arrow">→</span> {{ $ecomLabel }} → {{ $erpLabel }}
                    </button>
                </div>
                <div class="flow-diagram" x-show="mode==='erp_to_ecom'">
                    <span class="flow-node">{{ $erpLabel }}</span><span class="flow-arrow">→</span><span class="flow-node">{{ $ecomLabel }}</span>
                </div>
                <div class="flow-diagram" x-show="mode==='ecom_to_erp'">
                    <span class="flow-node">{{ $ecomLabel }}</span><span class="flow-arrow">→</span><span class="flow-node">{{ $erpLabel }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="dir-card">
        <div class="dir-pill"><span>−</span> Customer Settings</div>
        <div class="dir-body">
            <div class="dir-row" x-data="{ on: {{ $ss->isCustomerSyncEnabled() ? 'true' : 'false' }} }">
                <div class="dir-label">Enable Customer</div>
                <div class="toggle-wrap" style="padding-top:0">
                    <div class="toggle-track" :class="on?'on':''" @click="on=!on"><div class="toggle-thumb"></div></div>
                    <span class="toggle-label" x-text="on?'On':'Off'" :style="on?'color:#f97316;font-weight:700':''"></span>
                    <template x-if="on"><input type="hidden" name="customer_sync_enabled" value="1"></template>
                    <template x-if="!on"><input type="hidden" name="customer_sync_enabled" value="0"></template>
                </div>
            </div>
            <div class="dir-row" style="flex-direction:column;align-items:flex-start;gap:10px;" x-data="{ mode: '{{ $customerMode }}' }">
                <div class="dir-label">Sync Direction</div>
                <input type="hidden" name="customer_sync_mode" :value="mode">
                <div class="sync-mode-group">
                    <button type="button" :class="mode==='erp_to_ecom' ? 'smode-btn active' : 'smode-btn'" @click="mode='erp_to_ecom'">
                        <span class="smode-arrow">→</span> {{ $erpLabel }} → {{ $ecomLabel }}
                    </button>
                    <button type="button" :class="mode==='ecom_to_erp' ? 'smode-btn active' : 'smode-btn'" @click="mode='ecom_to_erp'">
                        <span class="smode-arrow">→</span> {{ $ecomLabel }} → {{ $erpLabel }}
                    </button>
                </div>
                <div class="flow-diagram" x-show="mode==='erp_to_ecom'">
                    <span class="flow-node">{{ $erpLabel }}</span><span class="flow-arrow">→</span><span class="flow-node">{{ $ecomLabel }}</span>
                </div>
                <div class="flow-diagram" x-show="mode==='ecom_to_erp'">
                    <span class="flow-node">{{ $ecomLabel }}</span><span class="flow-arrow">→</span><span class="flow-node">{{ $erpLabel }}</span>
                </div>
            </div>
        </div>
    </div>

    <div class="dir-card">
        <div class="dir-pill"><span>−</span> Sales Settings</div>
        <div class="dir-body">
            <div class="dir-row" x-data="{ on: {{ $ss->isSalesOrderSyncEnabled() ? 'true' : 'false' }} }">
                <div class="dir-label">Enable Sales Order</div>
                <div class="toggle-wrap" style="padding-top:0">
                    <div class="toggle-track" :class="on?'on':''" @click="on=!on"><div class="toggle-thumb"></div></div>
                    <span class="toggle-label" x-text="on?'On':'Off'" :style="on?'color:#f97316;font-weight:700':''"></span>
                    <template x-if="on"><input type="hidden" name="sales_order_sync_enabled" value="1"></template>
                    <template x-if="!on"><input type="hidden" name="sales_order_sync_enabled" value="0"></template>
                </div>
            </div>
            <div class="dir-row" style="flex-direction:column;align-items:flex-start;gap:10px;" x-data="{ mode: '{{ $salesMode }}' }">
                <div class="dir-label">Sales Order Sync Direction</div>
                <input type="hidden" name="sales_order_sync_mode" :value="mode">
                <div class="sync-mode-group">
                    <button type="button" :class="mode==='erp_to_ecom' ? 'smode-btn active' : 'smode-btn'" @click="mode='erp_to_ecom'">
                        <span class="smode-arrow">→</span> {{ $erpLabel }} → {{ $ecomLabel }}
                    </button>
                    <button type="button" :class="mode==='ecom_to_erp' ? 'smode-btn active' : 'smode-btn'" @click="mode='ecom_to_erp'">
                        <span class="smode-arrow">→</span> {{ $ecomLabel }} → {{ $erpLabel }}
                    </button>
                </div>
                <div class="flow-diagram" x-show="mode==='erp_to_ecom'">
                    <span class="flow-node">{{ $erpLabel }}</span><span class="flow-arrow">→</span><span class="flow-node">{{ $ecomLabel }}</span>
                </div>
                <div class="flow-diagram" x-show="mode==='ecom_to_erp'">
                    <span class="flow-node">{{ $ecomLabel }}</span><span class="flow-arrow">→</span><span class="flow-node">{{ $erpLabel }}</span>
                </div>
            </div>
        </div>
    </div>

</div>
