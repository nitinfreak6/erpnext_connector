{{--
    Shared sync entity detail header — used by product, order, and inventory info pages.

    Expected variables:
      $backUrl, $backLabel, $syncMode, $erpDisplayName, $ecomDisplayName
      $displayId, $title (optional), $mapping (optional SyncMapping)
      $syncLog or $lastLog (optional SyncLog), $fetchedAt (optional)
      $metaPills (optional array of ['label' => ..., 'value' => ...])
      $actions (optional HTML for action buttons)
--}}
@use('App\Services\Sync\SyncEntityState')
@php
    $isEcomToErp = ($syncMode ?? 'erp_to_ecom') === 'ecom_to_erp';
    $sourceLabel = $isEcomToErp ? $ecomDisplayName : $erpDisplayName;
    $targetLabel = $isEcomToErp ? $erpDisplayName : $ecomDisplayName;
    $log         = $syncLog ?? $lastLog ?? null;
    $wasPushed   = !empty($log);
    $pushOk      = $wasPushed && $log->status === 'success';
    $pushFailed  = $wasPushed && $log->status === 'failed';
    $entityStatus = SyncEntityState::displayStatus($mapping ?? null);
    $statusLabel  = SyncEntityState::displayLabel($entityStatus);
    $statusClass  = SyncEntityState::badgeClass($entityStatus);
@endphp

<div class="mb-4">
    <a href="{{ $backUrl }}" class="text-sm text-indigo-600 hover:underline">← {{ $backLabel }}</a>
</div>

@foreach(['success','error','info','warning'] as $level)
    @if(session($level))
        <div class="mb-4 px-4 py-3 rounded-lg text-sm border
            {{ $level === 'success' ? 'bg-emerald-50 border-emerald-200 text-emerald-700' : '' }}
            {{ $level === 'error'   ? 'bg-red-50 border-red-200 text-red-700'             : '' }}
            {{ $level === 'info'    ? 'bg-blue-50 border-blue-200 text-blue-700'           : '' }}
            {{ $level === 'warning' ? 'bg-yellow-50 border-yellow-200 text-yellow-700'     : '' }}
        ">{!! session($level) !!}</div>
    @endif
@endforeach

<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 mb-4">
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2 mb-1">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $isEcomToErp ? 'bg-violet-100 text-violet-700' : 'bg-indigo-100 text-indigo-700' }}">
                    {{ $sourceLabel }} → {{ $targetLabel }}
                </span>

                <span class="font-mono text-sm text-gray-700">#{{ $displayId }}</span>

                @if(!empty($title))
                    <span class="text-sm font-semibold text-gray-800">{{ $title }}</span>
                @endif

                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $statusClass }}">
                    {{ $statusLabel }}
                </span>

                @if($wasPushed)
                    @if($pushOk)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">✓ Pushed to {{ $targetLabel }}</span>
                    @elseif($pushFailed)
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-700">✗ Push failed</span>
                    @endif
                @endif
            </div>

            <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mt-1 text-xs text-gray-400">
                @if(!empty($fetchedAt))
                    <span>Fetched from {{ $sourceLabel }}: <strong class="text-gray-600">{{ \Carbon\Carbon::parse($fetchedAt)->format('Y-m-d H:i:s') }}</strong></span>
                @elseif($mapping?->last_synced_at)
                    <span>Fetched from {{ $sourceLabel }}: <strong class="text-gray-600">{{ $mapping->last_synced_at->format('Y-m-d H:i:s') }}</strong></span>
                @endif
                @if($wasPushed)
                    <span>·</span>
                    <span>Last push: <strong class="text-gray-600">{{ ($log->synced_at ?? $log->updated_at)?->format('Y-m-d H:i:s') }}</strong></span>
                @endif
                @if($mapping?->erp_id)
                    <span>·</span>
                    <span>{{ $erpDisplayName }} ID: <strong class="text-gray-600">#{{ $mapping->erp_id }}</strong></span>
                @endif
                @if($mapping?->ecom_id)
                    <span>·</span>
                    <span>{{ $ecomDisplayName }} ID: <strong class="text-gray-600">{{ $mapping->ecom_id }}</strong></span>
                @endif
            </div>
        </div>

        @if(!empty($actions))
            <div class="flex gap-2 shrink-0">{!! $actions !!}</div>
        @endif
    </div>

    @if(!empty($metaPills))
        <div class="flex flex-wrap gap-3 mt-4 pt-4 border-t border-gray-50 text-xs text-gray-500">
            @foreach($metaPills as $pill)
                <span>{{ $pill['label'] }}: <strong class="text-gray-700">{{ $pill['value'] }}</strong></span>
            @endforeach
        </div>
    @endif
</div>

@if(!empty($storedPayload))
<div class="bg-white rounded-xl border border-gray-200 shadow-sm p-5 mb-4">
    <div class="flex items-center justify-between mb-2">
        <h3 class="text-sm font-semibold text-gray-700">📦 Stored Payload</h3>
        <span class="text-xs text-gray-400">cached on fetch</span>
    </div>
    <pre class="bg-gray-50 rounded-lg p-3 text-xs text-gray-700 overflow-x-auto font-mono max-h-96 overflow-y-auto">{{ json_encode($storedPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
</div>
@endif
