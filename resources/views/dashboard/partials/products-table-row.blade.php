@php
    $isErpToEcomView = $syncMode === 'erp_to_ecom'
        || ($syncMode === 'bidirectional' && ($direction ?? 'erp_to_ecom') === 'erp_to_ecom');

    $erpCol = \App\Models\ProductCache::erpIdColumn();
    $erpPushId = $product->{$erpCol} ?? $product->erp_id ?? $product->odoo_id ?? null;
    if ($erpPushId === '' || $erpPushId === 0 || $erpPushId === '0') {
        $erpPushId = null;
    }

    $ecomPushId = $product->ecom_id ?? $product->ecom_product_id ?? null;

    $rowIndex = $rowIndex ?? 0;
    $rowId = $isErpToEcomView
        ? 'erp-' . ($erpPushId ?? 'row-' . $rowIndex)
        : 'ecom-' . ($ecomPushId ?? 'row-' . $rowIndex);

    // Must be unique per table row — never derive from ERP id alone (string IDs / empty cache).
    $toolKey = 'tool-ps-' . $rowIndex;

    $pushKeyEcom = 'ps-ecom-' . $rowIndex;
    $pushKeyErp  = 'ps-erp-' . $rowIndex;

    $showId = ($syncMode === 'ecom_to_erp')
        ? ($product->ecom_id ?? $product->erp_id ?? null)
        : ($erpPushId ?? $product->ecom_id ?? null);

    $deleteId = $isErpToEcomView ? $erpPushId : ($ecomPushId ?? null);
@endphp
<tr class="hover:bg-gray-50 transition" data-row-id="{{ $rowId }}"@if($deleteId) data-delete-id="{{ $deleteId }}"@endif>
    <td class="px-3 py-3 w-10 text-center">
        @if($deleteId)
        <input type="checkbox"
               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 h-4 w-4 cursor-pointer"
               x-model="selectedRows"
               value="{{ $rowId }}">
        @endif
    </td>

    @if($isErpToEcomView)
        <td class="px-4 py-3 text-gray-700 font-medium">#{{ $erpPushId ?? '—' }}</td>
        <td class="px-4 py-3">
            <div class="text-gray-900 font-medium">{{ Str::limit($product->name ?? '—', 40) }}</div>
        </td>
        <td class="px-4 py-3 text-gray-600 font-mono text-xs">{{ $product->default_code ?? '—' }}</td>
        <td class="px-4 py-3 text-gray-600">
            @if($product->ecom_product_id)
                <span class="font-mono text-xs">{{ $product->ecom_product_id }}</span>
            @else
                <span class="text-gray-400">—</span>
            @endif
        </td>
        <td class="px-4 py-3" data-cell="status">
            @php
                $displayStatus = \App\Models\ProductCache::normalizeDisplayStatus($product->ecom_status);
                $statusClass = \App\Models\ProductCache::displayBadgeClass($product->ecom_status);
                $statusLabel = \App\Models\ProductCache::displayLabel($product->ecom_status);
            @endphp
            <span class="px-2 py-1 {{ $statusClass }} rounded-md text-xs font-medium">{{ $statusLabel }}</span>
        </td>
        <td class="px-4 py-3" data-cell="message">
            @if($displayStatus !== 'sent' && filled($product->ecom_message))
                <p class="text-xs text-red-600 whitespace-pre-wrap break-words max-w-xs">{{ $product->ecom_message }}</p>
            @else
                <span class="text-gray-400 text-xs">—</span>
            @endif
        </td>
        <td class="px-4 py-3 text-gray-500 text-xs" data-cell="synced">
            {{ $product->ecom_synced_at ? $product->ecom_synced_at->diffForHumans() : '—' }}
        </td>
    @else
        <td class="px-4 py-3 text-gray-700 font-medium font-mono text-xs">{{ $product->ecom_id }}</td>
        <td class="px-4 py-3">
            <div class="text-gray-900 font-medium">
                {{ Str::limit($product->product_name ?? $product->ecom_handle ?? '—', 40) }}
            </div>
        </td>
        <td class="px-4 py-3 text-gray-600 font-mono text-xs">
            {{ $product->sku ?? $product->ecom_handle ?? '—' }}
        </td>
        <td class="px-4 py-3 text-gray-600" data-cell="erp-id">
            @if($product->erp_id && $product->erp_id !== '0')
                <span class="font-mono text-xs">#{{ $product->erp_id }}</span>
            @else
                <span class="text-gray-400 text-xs">Not pushed</span>
            @endif
        </td>
        <td class="px-4 py-3" data-cell="status">
            @php
                $displayStatus = $product->display_status
                    ?? \App\Services\Sync\EcomToErpProductState::displayStatus($product);
                $statusClass = \App\Services\Sync\SyncEntityState::badgeClass($displayStatus);
                $statusLabel = \App\Services\Sync\SyncEntityState::displayLabel($displayStatus);
            @endphp
            <span class="px-2 py-1 {{ $statusClass }} rounded-md text-xs font-medium">{{ $statusLabel }}</span>
        </td>
        <td class="px-4 py-3" data-cell="message">
            @if($displayStatus !== 'sent' && filled($product->sync_message))
                <p class="text-xs text-red-600 whitespace-pre-wrap break-words max-w-xs">{{ $product->sync_message }}</p>
            @else
                <span class="text-gray-400 text-xs">—</span>
            @endif
        </td>
        <td class="px-4 py-3 text-gray-500 text-xs" data-cell="synced">
            {{ $product->last_synced_at ? $product->last_synced_at->diffForHumans() : '—' }}
        </td>
    @endif

    <td class="px-4 py-3 text-right relative overflow-visible">
        <div class="flex items-center justify-end gap-2">
            <div class="relative">
                <button type="button"
                        @click="openToolId = openToolId === '{{ $toolKey }}' ? null : '{{ $toolKey }}'"
                        class="inline-flex items-center gap-1 text-xs text-gray-600 hover:text-gray-800 border border-gray-200 hover:border-gray-400 bg-white hover:bg-gray-50 px-2 py-1 rounded-lg transition">
                    <svg x-show='!!(loading[@json($pushKeyEcom)] || loading[@json($pushKeyErp)])' x-cloak class="animate-spin h-3 w-3 text-indigo-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    <span x-text='(loading[@json($pushKeyEcom)] || loading[@json($pushKeyErp)]) ? "Working…" : "Tools"'></span>
                    <svg x-show='!(loading[@json($pushKeyEcom)] || loading[@json($pushKeyErp)])' class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <div x-show="openToolId === '{{ $toolKey }}'"
                     x-cloak
                     @click.outside="openToolId = null"
                     class="absolute right-0 z-50 mt-1 w-56 bg-white border border-gray-200 rounded-xl shadow-xl py-1.5">

                    @if($showId)
                    <a href="{{ route('dashboard.products.show', $showId) }}"
                       class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 flex items-center gap-2 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        Product Info
                    </a>
                    @endif

                    @if(($syncMode === 'erp_to_ecom' || ($syncMode === 'bidirectional' && $isErpToEcomView)) && $erpPushId)
                    <div class="border-t border-gray-100 my-1"></div>
                    <button type="button"
                            @click='pushSingleEcom(@json($erpPushId), @json($pushKeyEcom))'
                            :disabled='!!loading[@json($pushKeyEcom)]'
                            class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 disabled:opacity-60 flex items-center gap-2 transition">
                        <svg x-show='!!loading[@json($pushKeyEcom)]' x-cloak class="animate-spin h-3.5 w-3.5 shrink-0 text-indigo-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <svg x-show='!loading[@json($pushKeyEcom)]' class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M16 12l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        <span x-text='loading[@json($pushKeyEcom)] ? "Pushing…" : {{ json_encode('Push to ' . $ecomDisplayName) }}'></span>
                    </button>
                    @endif

                    @if(($syncMode === 'ecom_to_erp' || ($syncMode === 'bidirectional' && !$isErpToEcomView)) && $ecomPushId)
                    <div class="border-t border-gray-100 my-1"></div>
                    <button type="button"
                            @click='pushSingleErp(@json((string) $ecomPushId), @json($pushKeyErp))'
                            :disabled='!!loading[@json($pushKeyErp)]'
                            class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 disabled:opacity-60 flex items-center gap-2 transition">
                        <svg x-show='!!loading[@json($pushKeyErp)]' x-cloak class="animate-spin h-3.5 w-3.5 shrink-0 text-indigo-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                        <svg x-show='!loading[@json($pushKeyErp)]' class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1M16 12l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        <span x-text='loading[@json($pushKeyErp)] ? "Pushing…" : {{ json_encode('Push to ' . $erpDisplayName) }}'></span>
                    </button>
                    @endif
                </div>
            </div>
        </div>
    </td>
</tr>
