@extends('dashboard.layout')
@section('title', app(\App\Services\SettingsService::class)->appName() . ' — Settings')
@section('page-title', 'Global Settings')

@section('content')

@if(session('success'))
<div class="mb-5 flex items-center gap-3 bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 rounded-xl text-sm">
    <svg class="w-4 h-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
    {{ session('success') }}
</div>
@endif

<style>
    .settings-card { background:#fff; border:1px solid #e5e7eb; border-radius:10px; margin-bottom:16px; overflow:hidden; }
    .settings-header { display:inline-flex; align-items:center; gap:8px; margin:20px 20px 0 20px; padding:8px 18px; border-radius:999px; font-size:13px; font-weight:700; color:#fff; cursor:pointer; user-select:none; letter-spacing:0.01em; transition:opacity 0.15s; }
    .settings-header:hover { opacity:0.9; }
    .settings-header .icon { font-size:15px; line-height:1; }
    .settings-header .chevron { margin-left:4px; transition:transform 0.2s; }
    .settings-header.open .chevron { transform:rotate(180deg); }
    .settings-divider { height:1px; background:#f3f4f6; margin:16px 0 0 0; }
    .settings-body { padding:8px 0 20px 0; }
    .settings-row { display:grid; grid-template-columns:180px 1fr; gap:12px; align-items:flex-start; padding:12px 24px; }
    .settings-label { font-size:13px; font-weight:600; color:#1f2937; padding-top:8px; line-height:1.4; }
    .settings-desc { font-size:11px; color:#9ca3af; margin-top:2px; }
    .settings-input { width:100%; border:1px solid #d1d5db; border-radius:6px; padding:7px 11px; font-size:13px; color:#1f2937; background:#fff; outline:none; transition:border-color 0.15s,box-shadow 0.15s; }
    .settings-input:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,0.1); }
    select.settings-input { cursor:pointer; }
    textarea.settings-input { resize:vertical; font-family:monospace; font-size:12px; }
    .secret-wrap { display:flex; gap:8px; }
    .secret-wrap .settings-input { font-family:monospace; }
    .reveal-btn { padding:7px 12px; font-size:11px; border:1px solid #d1d5db; border-radius:6px; background:#f9fafb; color:#6b7280; cursor:pointer; white-space:nowrap; transition:background 0.15s; }
    .reveal-btn:hover { background:#f3f4f6; }
    .reveal-btn:disabled { opacity:0.4; cursor:not-allowed; }
    .toggle-wrap { display:flex; align-items:center; gap:10px; padding-top:6px; }
    .toggle-track { position:relative; display:inline-flex; align-items:center; width:44px; height:24px; border-radius:999px; background:#d1d5db; cursor:pointer; transition:background 0.2s; }
    .toggle-track.on { background:#f97316; }
    /* dir-cards use their own scoped colour via inline style — no override needed */
    .toggle-thumb { position:absolute; left:3px; width:18px; height:18px; border-radius:50%; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.2); transition:transform 0.2s; }
    .toggle-track.on .toggle-thumb { transform:translateX(20px); }
    .toggle-label { font-size:13px; color:#6b7280; }
    .save-bar { position:sticky; bottom:0; left:0; right:0; background:rgba(255,255,255,0.95); backdrop-filter:blur(8px); border-top:1px solid #e5e7eb; padding:14px 0; margin-top:8px; display:flex; align-items:center; justify-content:space-between; }
    .save-btn { background:#f97316; color:#fff; font-size:13px; font-weight:700; padding:10px 28px; border-radius:8px; border:none; cursor:pointer; transition:background 0.15s; letter-spacing:0.01em; }
    .save-btn:hover { background:#ea6c00; }
    .erp-pill { display:inline-flex; align-items:center; gap:6px; padding:4px 12px; border-radius:999px; font-size:11px; font-weight:600; border:1.5px solid #d1d5db; cursor:pointer; transition:all 0.15s; background:#fff; color:#6b7280; }
    .erp-pill.selected { background:#1e293b; color:#fff; border-color:#1e293b; }
    .secret-badge { display:inline-flex; align-items:center; gap:3px; font-size:10px; color:#ef4444; background:#fef2f2; border:1px solid #fecaca; padding:1px 6px; border-radius:999px; margin-top:4px; }
</style>

<form method="POST" action="{{ route('dashboard.settings.update') }}" id="settings-form">
    @csrf
    @method('PUT')

    @php
        $sectionOrder = ['general', 'odoo', 'shopify', 'amazon'];
        $sectionConfig = [
            'general' => ['label' => 'Common Settings',  'gradient' => 'linear-gradient(135deg,#f97316,#ef4444)', 'open' => true],
            'odoo'    => ['label' => 'Odoo Settings',     'gradient' => 'linear-gradient(135deg,#7c3aed,#6366f1)', 'open' => false],
            'shopify' => ['label' => 'Shopify Settings',  'gradient' => 'linear-gradient(135deg,#059669,#10b981)', 'open' => false],
            'amazon'  => ['label' => 'Amazon Settings',   'gradient' => 'linear-gradient(135deg,#d97706,#f59e0b)', 'open' => false],
        ];
    @endphp

    @foreach($sectionOrder as $groupKey)
        @php
            $settings = $groups[$groupKey] ?? collect();
            $cfg      = $sectionConfig[$groupKey];
            $domId    = 'section-' . $groupKey;
        @endphp

        <div class="settings-card">
            <div class="settings-header {{ $cfg['open'] ? 'open' : '' }}"
                 style="background:{{ $cfg['gradient'] }}"
                 onclick="toggleSection('{{ $domId }}', this)">
                <span class="icon">{{ $cfg['open'] ? '−' : '+' }}</span>
                {{ $cfg['label'] }}
                <svg class="chevron w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M19 9l-7 7-7-7"/>
                </svg>
            </div>
            <div class="settings-divider"></div>
            <div id="{{ $domId }}" class="settings-body" style="{{ $cfg['open'] ? '' : 'display:none' }}">
                @if($settings->isEmpty())
                    <div class="px-6 py-6 text-center text-sm text-gray-400">No settings configured.</div>
                @else
                    @foreach($settings->sortBy('sort_order') as $setting)
                    @php
                        $fieldType    = $setting->field_type ?? 'text';
                        $currentValue = $setting->getDecryptedValue() ?? $setting->default_value ?? '';
                    @endphp
                    <div class="settings-row">
                        {{-- Label --}}
                        <div>
                            <div class="settings-label">{{ $setting->label }}</div>
                            @if($setting->description)
                                <div class="settings-desc">{{ $setting->description }}</div>
                            @endif
                            @if($setting->is_secret)
                                <div class="secret-badge">
                                    <svg width="10" height="10" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                                    Secret
                                </div>
                            @endif
                        </div>

                        {{-- Input --}}
                        <div>

                            {{-- ── ERP Driver pill selector ──────────────── --}}
                            @if($setting->key === 'erp_driver')
                            <div x-data="{ selected: '{{ $currentValue ?: 'odoo' }}' }" style="padding-top:4px">
                                <input type="hidden" name="{{ $setting->key }}" :value="selected">
                                <div style="display:flex;gap:8px;flex-wrap:wrap">
                                    @foreach(['odoo' => '🔗 Odoo', 'sap' => '⚡ SAP', 'netsuite' => '☁️ NetSuite'] as $val => $lbl)
                                    <button type="button"
                                            @click="selected = '{{ $val }}'"
                                            :class="selected === '{{ $val }}' ? 'selected' : ''"
                                            class="erp-pill">
                                        {{ $lbl }}
                                    </button>
                                    @endforeach
                                </div>
                                <p style="font-size:11px;color:#9ca3af;margin-top:6px">
                                    Active ERP adapter. Change the <strong>ERP Display Name</strong> above to match.
                                </p>
                            </div>

                            {{-- ── Toggle ──────────────────────────────────── --}}
                            @elseif($fieldType === 'toggle')
                            <div x-data="{ on: {{ in_array($currentValue, ['1','true','yes','on']) ? 'true' : 'false' }} }"
                                 class="toggle-wrap">
                                <div class="toggle-track" :class="on ? 'on' : ''" @click="on = !on">
                                    <div class="toggle-thumb"></div>
                                </div>
                                <span class="toggle-label" x-text="on ? 'Enabled' : 'Disabled'"></span>
                                <input type="hidden" name="{{ $setting->key }}" :value="on ? '1' : '0'">
                            </div>

                            {{-- ── Textarea ─────────────────────────────────── --}}
                            @elseif($fieldType === 'textarea')
                            <textarea name="{{ $setting->key }}"
                                      rows="3"
                                      placeholder="{{ $setting->default_value }}"
                                      class="settings-input">{{ $currentValue }}</textarea>

                            {{-- ── Number ───────────────────────────────────── --}}
                            @elseif($fieldType === 'number')
                            <input type="number"
                                   name="{{ $setting->key }}"
                                   value="{{ $currentValue }}"
                                   placeholder="{{ $setting->default_value }}"
                                   style="width:120px"
                                   class="settings-input">

                            {{-- ── Secret ───────────────────────────────────── --}}
                            @elseif($setting->is_secret)
                            <div x-data="{ revealed: false, loading: false, val: '' }" class="secret-wrap">
                                <input :type="revealed ? 'text' : 'password'"
                                       name="{{ $setting->key }}"
                                       :value="revealed ? val : ''"
                                       :placeholder="revealed ? '' : '{{ $setting->getMaskedValue() ?: 'Not set — enter value' }}'"
                                       class="settings-input">
                                @if(auth()->user()->can('reveal-secrets'))
                                <button type="button" class="reveal-btn"
                                        :disabled="revealed || loading"
                                        @click="loading=true; fetch('{{ route('dashboard.settings.reveal', $setting) }}',{headers:{'X-Requested-With':'XMLHttpRequest','Accept':'application/json'}}).then(r=>r.json()).then(d=>{val=d.value;revealed=true;loading=false}).catch(()=>loading=false)">
                                    <span x-show="!loading && !revealed">Reveal</span>
                                    <span x-show="loading">…</span>
                                    <span x-show="revealed">✓ Shown</span>
                                </button>
                                @endif
                            </div>
                            <div style="font-size:11px;color:#9ca3af;margin-top:4px">Leave blank to keep existing value.</div>

                            {{-- ── Plain text (app_name, erp_display_name, etc.) ── --}}
                            @else
                            <input type="text"
                                   name="{{ $setting->key }}"
                                   value="{{ $currentValue }}"
                                   placeholder="{{ $setting->default_value }}"
                                   class="settings-input"
                                   @if($setting->key === 'app_name') style="font-weight:600" @endif>
                            @if($setting->key === 'app_name')
                                <p style="font-size:11px;color:#9ca3af;margin-top:4px">
                                    Used in the browser tab and page header across the whole app.
                                </p>
                            @endif
                            @if($setting->key === 'erp_display_name')
                                <p style="font-size:11px;color:#9ca3af;margin-top:4px">
                                    Replaces "Odoo" wherever ERP labels appear — field config columns, product pages, log messages.
                                </p>
                            @endif
                            @endif

                        </div>
                    </div>
                    @endforeach
                @endif
            </div>
        </div>
    @endforeach

    {{-- ═══════════════════════════════════════════════════════════════════
         Sync Direction Settings
         Three cards: Product Settings | Customer Settings | Sales Settings
         Each card has a toggle + a 3-option sync-mode selector:
           [ERP → Shopify]  [Shopify → ERP]  [⇄ Both]
    ═══════════════════════════════════════════════════════════════════ --}}
    @php
        /** @var \App\Services\SettingsService $ss */
        $ss        = app(\App\Services\SettingsService::class);
        $erpLabel  = $ss->erpDisplayName();   // e.g. "Odoo"
        $ecomLabel = $ss->ecomDisplayName();  // e.g. "Shopify"
    @endphp

    <style>
        /* ── Direction cards ──────────────────────────────────────── */
        .dir-grid  { display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr)); gap:16px; margin-bottom:20px; }
        .dir-card  { background:#fff; border:1px solid #e5e7eb; border-radius:12px; overflow:hidden; }

        /* orange pill header */
        .dir-pill  { display:inline-flex; align-items:center; gap:8px;
                     margin:18px 18px 0 18px; padding:8px 20px;
                     border-radius:999px; font-size:13px; font-weight:700; color:#fff;
                     background:linear-gradient(135deg,#f97316,#ef4444);
                     user-select:none; }

        .dir-body  { padding:14px 18px 18px; }
        .dir-row   { display:flex; align-items:center; justify-content:space-between;
                     padding:10px 0; border-bottom:1px solid #f3f4f6; gap:12px; }
        .dir-row:last-child { border-bottom:none; padding-bottom:0; }
        .dir-label { font-size:12px; font-weight:600; color:#374151; white-space:nowrap; }

        /* ── Sync-mode selector ───────────────────────────────────── */
        /*
         * Three pill buttons in a row:
         *   [ERP → Shopify]   [Shopify → ERP]   [⇄ Both]
         * Active pill: solid orange  |  Inactive: light grey border
         */
        .sync-mode-group { display:flex; gap:6px; flex-wrap:wrap; }
        .smode-btn {
            display:inline-flex; align-items:center; gap:5px;
            padding:5px 11px; border-radius:999px; font-size:11px; font-weight:600;
            border:1.5px solid #e5e7eb; background:#f9fafb; color:#6b7280;
            cursor:pointer; transition:all .15s; user-select:none; white-space:nowrap;
        }
        .smode-btn:hover { border-color:#f97316; color:#f97316; background:#fff7ed; }
        .smode-btn.active {
            background:linear-gradient(135deg,#f97316,#ef4444);
            border-color:transparent; color:#fff;
            box-shadow:0 2px 8px rgba(249,115,22,.35);
        }
        .smode-arrow { font-size:13px; line-height:1; }

        /* small flow diagram shown below active mode */
        .flow-diagram {
            display:flex; align-items:center; gap:6px;
            margin-top:10px; padding:8px 12px;
            background:#fff7ed; border:1px solid #fed7aa;
            border-radius:8px; font-size:11px; color:#c2410c; font-weight:600;
        }
        .flow-diagram .flow-node {
            background:#fff; border:1.5px solid #f97316; border-radius:6px;
            padding:3px 9px; font-size:11px; color:#ea580c; font-weight:700;
        }
        .flow-diagram .flow-arrow { color:#f97316; font-size:14px; font-weight:700; }
    </style>

    <div class="dir-grid">

        {{-- ══════════════════════════════════════════════════════════
             PRODUCT SETTINGS
        ══════════════════════════════════════════════════════════ --}}
        @php $productMode = $ss->productSyncMode(); @endphp
        <div class="dir-card">
            <div class="dir-pill">
                <span>−</span> Product Settings
            </div>
            <div class="dir-body">

                {{-- Enable Product toggle --}}
                <div class="dir-row"
                     x-data="{ on: {{ $ss->isProductSyncEnabled() ? 'true' : 'false' }} }">
                    <div class="dir-label">Enable Product</div>
                    <div class="toggle-wrap" style="padding-top:0">
                        <div class="toggle-track" :class="on?'on':''" @click="on=!on">
                            <div class="toggle-thumb"></div>
                        </div>
                        <span class="toggle-label" x-text="on?'On':'Off'"
                              :style="on?'color:#f97316;font-weight:700':''"></span>
                        <input type="hidden" name="product_sync_enabled" :value="on?'1':'0'">
                    </div>
                </div>

                {{-- Sync Mode selector --}}
                <div class="dir-row" style="flex-direction:column; align-items:flex-start; gap:10px;"
                     x-data="{ mode: '{{ $productMode }}' }">
                    <div class="dir-label">Sync Direction</div>
                    <input type="hidden" name="product_sync_mode" :value="mode">

                    <div class="sync-mode-group">
                        {{-- Option 1: ERP → Shopify --}}
                        <button type="button"
                                :class="mode==='erp_to_ecom' ? 'smode-btn active' : 'smode-btn'"
                                @click="mode='erp_to_ecom'">
                            <span class="smode-arrow">→</span>
                            {{ $erpLabel }} → {{ $ecomLabel }}
                        </button>

                        {{-- Option 2: Shopify → ERP --}}
                        <button type="button"
                                :class="mode==='ecom_to_erp' ? 'smode-btn active' : 'smode-btn'"
                                @click="mode='ecom_to_erp'">
                            <span class="smode-arrow">→</span>
                            {{ $ecomLabel }} → {{ $erpLabel }}
                        </button>

                        {{-- Option 3: Both / Bidirectional --}}
                        <button type="button"
                                :class="mode==='bidirectional' ? 'smode-btn active' : 'smode-btn'"
                                @click="mode='bidirectional'">
                            <span class="smode-arrow">⇄</span>
                            Both
                        </button>
                    </div>

                    {{-- Live flow diagram --}}
                    <div class="flow-diagram" x-show="mode==='erp_to_ecom'">
                        <span class="flow-node">{{ $erpLabel }}</span>
                        <span class="flow-arrow">→</span>
                        <span class="flow-node">{{ $ecomLabel }}</span>
                    </div>
                    <div class="flow-diagram" x-show="mode==='ecom_to_erp'">
                        <span class="flow-node">{{ $ecomLabel }}</span>
                        <span class="flow-arrow">→</span>
                        <span class="flow-node">{{ $erpLabel }}</span>
                    </div>
                    <div class="flow-diagram" x-show="mode==='bidirectional'">
                        <span class="flow-node">{{ $erpLabel }}</span>
                        <span class="flow-arrow">⇄</span>
                        <span class="flow-node">{{ $ecomLabel }}</span>
                    </div>
                </div>

                

            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════
             CUSTOMER SETTINGS
        ══════════════════════════════════════════════════════════ --}}
        @php $customerMode = $ss->customerSyncMode(); @endphp
        <div class="dir-card">
            <div class="dir-pill">
                <span>−</span> Customer Settings
            </div>
            <div class="dir-body">

                {{-- Enable Customer toggle --}}
                <div class="dir-row"
                     x-data="{ on: {{ $ss->isCustomerSyncEnabled() ? 'true' : 'false' }} }">
                    <div class="dir-label">Enable Customer</div>
                    <div class="toggle-wrap" style="padding-top:0">
                        <div class="toggle-track" :class="on?'on':''" @click="on=!on">
                            <div class="toggle-thumb"></div>
                        </div>
                        <span class="toggle-label" x-text="on?'On':'Off'"
                              :style="on?'color:#f97316;font-weight:700':''"></span>
                        <input type="hidden" name="customer_sync_enabled" :value="on?'1':'0'">
                    </div>
                </div>

                {{-- Sync Mode selector --}}
                <div class="dir-row" style="flex-direction:column; align-items:flex-start; gap:10px;"
                     x-data="{ mode: '{{ $customerMode }}' }">
                    <div class="dir-label">Sync Direction</div>
                    <input type="hidden" name="customer_sync_mode" :value="mode">

                    <div class="sync-mode-group">
                        <button type="button"
                                :class="mode==='erp_to_ecom' ? 'smode-btn active' : 'smode-btn'"
                                @click="mode='erp_to_ecom'">
                            <span class="smode-arrow">→</span>
                            {{ $erpLabel }} → {{ $ecomLabel }}
                        </button>
                        <button type="button"
                                :class="mode==='ecom_to_erp' ? 'smode-btn active' : 'smode-btn'"
                                @click="mode='ecom_to_erp'">
                            <span class="smode-arrow">→</span>
                            {{ $ecomLabel }} → {{ $erpLabel }}
                        </button>
                        <button type="button"
                                :class="mode==='bidirectional' ? 'smode-btn active' : 'smode-btn'"
                                @click="mode='bidirectional'">
                            <span class="smode-arrow">⇄</span>
                            Both
                        </button>
                    </div>

                    <div class="flow-diagram" x-show="mode==='erp_to_ecom'">
                        <span class="flow-node">{{ $erpLabel }}</span>
                        <span class="flow-arrow">→</span>
                        <span class="flow-node">{{ $ecomLabel }}</span>
                    </div>
                    <div class="flow-diagram" x-show="mode==='ecom_to_erp'">
                        <span class="flow-node">{{ $ecomLabel }}</span>
                        <span class="flow-arrow">→</span>
                        <span class="flow-node">{{ $erpLabel }}</span>
                    </div>
                    <div class="flow-diagram" x-show="mode==='bidirectional'">
                        <span class="flow-node">{{ $erpLabel }}</span>
                        <span class="flow-arrow">⇄</span>
                        <span class="flow-node">{{ $ecomLabel }}</span>
                    </div>
                </div>

            </div>
        </div>

        {{-- ══════════════════════════════════════════════════════════
             SALES SETTINGS
        ══════════════════════════════════════════════════════════ --}}
        @php
            $salesMode    = $ss->salesOrderSyncMode();
            $dispatchMode = $ss->dispatchSyncMode();
        @endphp
        <div class="dir-card">
            <div class="dir-pill">
                <span>−</span> Sales Settings
            </div>
            <div class="dir-body">

                {{-- Enable Sales Order --}}
                <div class="dir-row"
                     x-data="{ on: {{ $ss->isSalesOrderSyncEnabled() ? 'true' : 'false' }} }">
                    <div class="dir-label">Enable Sales Order</div>
                    <div class="toggle-wrap" style="padding-top:0">
                        <div class="toggle-track" :class="on?'on':''" @click="on=!on">
                            <div class="toggle-thumb"></div>
                        </div>
                        <span class="toggle-label" x-text="on?'On':'Off'"
                              :style="on?'color:#f97316;font-weight:700':''"></span>
                        <input type="hidden" name="sales_order_sync_enabled" :value="on?'1':'0'">
                    </div>
                </div>

                {{-- Sales Order Sync Mode --}}
                <div class="dir-row" style="flex-direction:column; align-items:flex-start; gap:10px;"
                     x-data="{ mode: '{{ $salesMode }}' }">
                    <div class="dir-label">Sales Order Sync Direction</div>
                    <input type="hidden" name="sales_order_sync_mode" :value="mode">

                    <div class="sync-mode-group">
                        <button type="button"
                                :class="mode==='erp_to_ecom' ? 'smode-btn active' : 'smode-btn'"
                                @click="mode='erp_to_ecom'">
                            <span class="smode-arrow">→</span>
                            {{ $erpLabel }} → {{ $ecomLabel }}
                        </button>
                        <button type="button"
                                :class="mode==='ecom_to_erp' ? 'smode-btn active' : 'smode-btn'"
                                @click="mode='ecom_to_erp'">
                            <span class="smode-arrow">→</span>
                            {{ $ecomLabel }} → {{ $erpLabel }}
                        </button>
                        <button type="button"
                                :class="mode==='bidirectional' ? 'smode-btn active' : 'smode-btn'"
                                @click="mode='bidirectional'">
                            <span class="smode-arrow">⇄</span>
                            Both
                        </button>
                    </div>

                    <div class="flow-diagram" x-show="mode==='erp_to_ecom'">
                        <span class="flow-node">{{ $erpLabel }}</span>
                        <span class="flow-arrow">→</span>
                        <span class="flow-node">{{ $ecomLabel }}</span>
                    </div>
                    <div class="flow-diagram" x-show="mode==='ecom_to_erp'">
                        <span class="flow-node">{{ $ecomLabel }}</span>
                        <span class="flow-arrow">→</span>
                        <span class="flow-node">{{ $erpLabel }}</span>
                    </div>
                    <div class="flow-diagram" x-show="mode==='bidirectional'">
                        <span class="flow-node">{{ $erpLabel }}</span>
                        <span class="flow-arrow">⇄</span>
                        <span class="flow-node">{{ $ecomLabel }}</span>
                    </div>
                </div>

                

            </div>
        </div>

    </div>{{-- /dir-grid --}}

    {{-- Sync triggers --}}
    @if(auth()->user()->can('trigger-sync'))
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
                @foreach([
                    'products'         => ['label' => 'Shopify Products',  'color' => '#6366f1'],
                    'inventory'        => ['label' => 'Shopify Inventory', 'color' => '#6366f1'],
                    'orders'           => ['label' => 'Shopify Orders',    'color' => '#6366f1'],
                    'customers'        => ['label' => 'Customers',         'color' => '#6366f1'],
                    'amazon_products'  => ['label' => 'Amazon Products',   'color' => '#d97706'],
                    'amazon_orders'    => ['label' => 'Amazon Orders',     'color' => '#d97706'],
                    'amazon_inventory' => ['label' => 'Amazon Inventory',  'color' => '#d97706'],
                ] as $type => $info)
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

    {{-- Save bar --}}
    <div class="save-bar">
        <p style="font-size:12px;color:#9ca3af">Changes take effect immediately after saving.</p>
        <button type="submit" class="save-btn">Save Changes</button>
    </div>
</form>

<script>
function toggleSection(id, header) {
    const body   = document.getElementById(id);
    const isOpen = body.style.display !== 'none';
    body.style.display = isOpen ? 'none' : 'block';
    header.classList.toggle('open', !isOpen);
    const icon = header.querySelector('.icon');
    if (icon) icon.textContent = isOpen ? '+' : '−';
}
</script>
@endsection