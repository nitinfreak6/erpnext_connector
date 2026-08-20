@php
    $isErpToEcomView = $syncMode === 'erp_to_ecom'
        || ($syncMode === 'bidirectional' && ($direction ?? 'erp_to_ecom') === 'erp_to_ecom');

    $dispatchFlow = $dispatchFlow ?? ($syncMode === 'ecom_to_erp' ? 'ecom_to_erp' : 'erp_to_ecom');
    $isEcomDispatchView = $dispatchFlow === 'ecom_to_erp';

    $erpId  = $mapping->erp_id ?? null;
    $ecomId = $mapping->ecom_id ?? null;

    $rowId = $isErpToEcomView
        ? 'erp-' . ($erpId ?: 'unknown')
        : 'ecom-' . ($ecomId ?? 'unknown');

    $toolKey = 'tool-' . $rowId;
    $rowIndex = $rowIndex ?? abs(crc32($rowId));
    $pushKeyEcom = 'os-ecom-' . $rowIndex . '-' . ($erpId ?: '0');
    $pushKeyErp  = 'os-erp-' . $rowIndex . '-' . substr(md5((string) ($ecomId ?? 'x')), 0, 10);
    $dispatchFetchKey = 'os-dfetch-' . $rowIndex . '-' . ($erpId ?: '0');
    $dispatchPostKey  = 'os-dpost-' . $rowIndex . '-' . ($erpId ?: '0');
    $deleteId         = $isErpToEcomView ? ($erpId ?: null) : ($ecomId ?? null);

    $displayStatus = $mapping->display_status ?? \App\Services\Sync\SyncEntityState::displayStatus($mapping);
    $statusClass   = \App\Services\Sync\SyncEntityState::badgeClass($displayStatus);
    $statusLabel   = \App\Services\Sync\SyncEntityState::displayLabel($displayStatus);
    $message       = $mapping->display_message ?? $mapping->sync_message;
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
    <td class="px-4 py-3">
        @if(str_starts_with($mapping->entity_type, 'amazon'))
            <span class="px-2 py-0.5 bg-amber-100 text-amber-800 rounded text-xs font-medium">Amazon</span>
        @else
            <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 rounded text-xs font-medium">{{ $ecomDisplayName }}</span>
        @endif
    </td>

    @if($isErpToEcomView)
        <td class="px-4 py-3 font-mono text-xs text-gray-800">#{{ $erpId ?: '—' }}</td>
        <td class="px-4 py-3 font-mono text-xs text-gray-600">{{ $ecomId ?: '—' }}</td>
    @else
        <td class="px-4 py-3 font-mono text-xs text-gray-800">{{ $ecomId ?: '—' }}</td>
        <td class="px-4 py-3 font-mono text-xs text-gray-600">
            @if($erpId && $erpId !== '0')
                #{{ $erpId }}
            @else
                <span class="text-gray-400">Not pushed</span>
            @endif
        </td>
    @endif

    <td class="px-4 py-3 text-gray-700 font-medium text-xs">{{ $mapping->ecom_handle ?? '—' }}</td>

    <td class="px-4 py-3" data-cell="status">
        <span class="px-2 py-1 {{ $statusClass }} rounded-md text-xs font-medium">{{ $statusLabel }}</span>
    </td>
    <td class="px-4 py-3" data-cell="message">
        @if(filled($message))
            @php
                $messageClass = ($message === \App\Services\Sync\SyncEntityState::DISPATCH_MSG_NO_UPDATE)
                    ? 'text-gray-600'
                    : 'text-red-600';
            @endphp
            <p class="text-xs {{ $messageClass }} whitespace-pre-wrap break-words max-w-xs">{{ $message }}</p>
        @else
            <span class="text-gray-400 text-xs">—</span>
        @endif
    </td>
    <td class="px-4 py-3">
        @php
            $dispatchLabel = \App\Services\Sync\SyncEntityState::dispatchDisplayLabel($mapping->dispatch_status ?? null);
            $dispatchClass = \App\Services\Sync\SyncEntityState::dispatchBadgeClass($mapping->dispatch_status ?? null);
        @endphp
        <span class="px-2 py-0.5 {{ $dispatchClass }} rounded text-xs font-medium">{{ $dispatchLabel }}</span>
    </td>
    <td class="px-4 py-3 text-gray-500 text-xs" data-cell="synced">
        {{ $mapping->last_synced_at ? $mapping->last_synced_at->diffForHumans() : '—' }}
    </td>

    <td class="px-4 py-3 text-right relative overflow-visible">
        <div class="flex items-center justify-end gap-2">
            <div class="relative">
                @php
                    $toolLoadingKeys = array_filter([
                        $pushKeyEcom, $pushKeyErp, $dispatchFetchKey, $dispatchPostKey,
                    ]);
                    $toolLoadingJson = json_encode($toolLoadingKeys);
                @endphp
                <button type="button"
                        @click="openToolId = openToolId === '{{ $toolKey }}' ? null : '{{ $toolKey }}'"
                        class="inline-flex items-center gap-1 text-xs text-gray-600 hover:text-gray-800 border border-gray-200 hover:border-gray-400 bg-white hover:bg-gray-50 px-2 py-1 rounded-lg transition">
                    <svg x-show='{{ $toolLoadingJson }}.some(k => loading[k])' x-cloak class="animate-spin h-3 w-3 text-indigo-600" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    <span x-text='{{ $toolLoadingJson }}.some(k => loading[k]) ? "Working…" : "Tools"'></span>
                    <svg x-show='!{{ $toolLoadingJson }}.some(k => loading[k])' class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>

                <div x-show="openToolId === '{{ $toolKey }}'"
                     x-cloak
                     @click.outside="openToolId = null"
                     class="absolute right-0 z-50 mt-1 w-56 bg-white border border-gray-200 rounded-xl shadow-xl py-1.5">

                    @php
                        $orderInfoRoute = $erpId
                            ? route('dashboard.orders.sales-info', $erpId)
                            : ($ecomId ? route('dashboard.orders.sales-info-by-ecom', $ecomId) : null);
                    @endphp
                    @if($orderInfoRoute)
                    <a href="{{ $orderInfoRoute }}"
                       class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-indigo-50 hover:text-indigo-700 flex items-center gap-2 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>
                        </svg>
                        Order Info
                    </a>
                    @endif

                    @if($erpId && $erpId !== '0')
                    <a href="{{ route('dashboard.orders.dispatch-info', $erpId) }}"
                       class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-purple-50 hover:text-purple-700 flex items-center gap-2 transition">
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/>
                        </svg>
                        Dispatch Info
                    </a>
                    @endif

                    @if(($syncMode === 'ecom_to_erp' || ($syncMode === 'bidirectional' && !$isErpToEcomView)) && $ecomId && $ecomId !== '0' && \App\Services\Sync\SyncEntityState::needsPush($mapping))
                    <div class="border-t border-gray-100 my-1"></div>
                    <button type="button"
                            @click='pushSingleErp(@json((string) $ecomId), @json($pushKeyErp))'
                            :disabled='!!loading[@json($pushKeyErp)]'
                            class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 disabled:opacity-60 flex items-center gap-2 transition">
                        <span x-text='loading[@json($pushKeyErp)] ? "Pushing…" : {{ json_encode('Push to ' . $erpDisplayName) }}'></span>
                    </button>
                    @endif

                    @if(($syncMode === 'erp_to_ecom' || ($syncMode === 'bidirectional' && $isErpToEcomView)) && $erpId && (!$ecomId || \App\Services\Sync\SyncEntityState::needsPush($mapping)))
                    <div class="border-t border-gray-100 my-1"></div>
                    <button type="button"
                            @click='pushSingleEcom(@json((string) $erpId), @json($pushKeyEcom))'
                            :disabled='!!loading[@json($pushKeyEcom)]'
                            class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-emerald-50 hover:text-emerald-700 disabled:opacity-60 flex items-center gap-2 transition">
                        <span x-text='loading[@json($pushKeyEcom)] ? "Pushing…" : {{ json_encode('Push to ' . $ecomDisplayName) }}'></span>
                    </button>
                    @endif

                    @if($isEcomDispatchView && $erpId && $erpId !== '0' && $ecomId && $ecomId !== '0')
                    <div class="border-t border-gray-100 my-1"></div>
                    <button type="button"
                            @click='dispatchFetch(@json((string) $erpId), @json($dispatchFetchKey))'
                            :disabled='!!loading[@json($dispatchFetchKey)]'
                            class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-purple-50 hover:text-purple-700 disabled:opacity-60 flex items-center gap-2 transition">
                        <span x-text='loading[@json($dispatchFetchKey)] ? "Fetching…" : "Fetch Dispatch"'></span>
                    </button>
                    <button type="button"
                            @click='dispatchPost(@json((string) $erpId), @json($dispatchPostKey))'
                            :disabled='!!loading[@json($dispatchPostKey)]'
                            class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-teal-50 hover:text-teal-700 disabled:opacity-60 flex items-center gap-2 transition">
                        <span x-text='loading[@json($dispatchPostKey)] ? "Posting…" : "Post Dispatch"'></span>
                    </button>
                    @elseif(!$isEcomDispatchView && $isErpToEcomView && $erpId)
                    <div class="border-t border-gray-100 my-1"></div>
                    <button type="button"
                            @click='dispatchFetch(@json((string) $erpId), @json($dispatchFetchKey))'
                            :disabled='!!loading[@json($dispatchFetchKey)]'
                            class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-purple-50 hover:text-purple-700 disabled:opacity-60 flex items-center gap-2 transition">
                        <span x-text='loading[@json($dispatchFetchKey)] ? "Fetching…" : "Fetch Dispatch"'></span>
                    </button>
                    <button type="button"
                            @click='dispatchPost(@json((string) $erpId), @json($dispatchPostKey))'
                            :disabled='!!loading[@json($dispatchPostKey)]'
                            class="w-full text-left px-4 py-2 text-xs text-gray-700 hover:bg-teal-50 hover:text-teal-700 disabled:opacity-60 flex items-center gap-2 transition">
                        <span x-text='loading[@json($dispatchPostKey)] ? "Posting…" : "Post Dispatch"'></span>
                    </button>
                    @elseif($isEcomDispatchView && (!$erpId || $erpId === '0'))
                    <div class="border-t border-gray-100 my-1"></div>
                    <div class="px-4 py-2 text-xs text-amber-600">Post to {{ $erpDisplayName }} first for dispatch</div>
                    @endif
                </div>
            </div>
        </div>
    </td>
</tr>
