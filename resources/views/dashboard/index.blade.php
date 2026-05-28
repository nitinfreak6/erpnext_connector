@extends('dashboard.layout')
@section('title', 'Overview')
@section('page-title', 'Overview')

@section('content')

{{-- ── Stat cards ── --}}
<div class="grid grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    @php
    $cards = [
        ['label' => $ecomDisplayName . ' Products',  'value' => $stats['shopify']['products'],  'color' => 'indigo',  'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
        ['label' => $ecomDisplayName . ' Orders',    'value' => $stats['shopify']['orders'],    'color' => 'violet',  'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2'],
        ['label' => 'Amazon Products',   'value' => $stats['amazon']['products'],   'color' => 'amber',   'icon' => 'M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z'],
        ['label' => 'Amazon Orders',     'value' => $stats['amazon']['orders'],     'color' => 'orange',  'icon' => 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8l1 12h12l1-12'],
    ];
    @endphp

    @foreach($cards as $card)
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5 flex items-center gap-4">
        <div class="w-12 h-12 bg-{{ $card['color'] }}-50 rounded-xl flex items-center justify-center shrink-0">
            <svg class="w-6 h-6 text-{{ $card['color'] }}-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="{{ $card['icon'] }}"/>
            </svg>
        </div>
        <div>
            <div class="text-2xl font-bold text-gray-900">{{ number_format($card['value']) }}</div>
            <div class="text-xs text-gray-500 mt-0.5">{{ $card['label'] }}</div>
        </div>
    </div>
    @endforeach
</div>

{{-- ── Health + Queue row ── --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4 mb-6">

    {{-- Sync Health 24h --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-gray-700 mb-4">Sync Health (24h)</h3>
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="w-2.5 h-2.5 bg-green-500 rounded-full"></div>
                    <span class="text-sm text-gray-600">Success</span>
                </div>
                <span class="font-semibold text-green-700">{{ number_format($health['success']) }}</span>
            </div>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="w-2.5 h-2.5 bg-red-500 rounded-full"></div>
                    <span class="text-sm text-gray-600">Failed</span>
                </div>
                <span class="font-semibold text-red-700">{{ number_format($health['failed']) }}</span>
            </div>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <div class="w-2.5 h-2.5 bg-yellow-400 rounded-full"></div>
                    <span class="text-sm text-gray-600">Pending</span>
                </div>
                <span class="font-semibold text-yellow-700">{{ number_format($health['pending']) }}</span>
            </div>

            @php $total = $health['success'] + $health['failed']; $rate = $total > 0 ? round($health['success'] / $total * 100) : 100; @endphp
            <div class="pt-2 border-t border-gray-100">
                <div class="flex justify-between text-xs text-gray-500 mb-1">
                    <span>Success rate</span>
                    <span class="font-medium {{ $rate >= 90 ? 'text-green-600' : ($rate >= 70 ? 'text-yellow-600' : 'text-red-600') }}">{{ $rate }}%</span>
                </div>
                <div class="w-full bg-gray-100 rounded-full h-1.5">
                    <div class="h-1.5 rounded-full {{ $rate >= 90 ? 'bg-green-500' : ($rate >= 70 ? 'bg-yellow-400' : 'bg-red-500') }}"
                         style="width: {{ $rate }}%"></div>
                </div>
            </div>
        </div>
    </div>

    {{-- Queue depths --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-gray-700 mb-4">Queue Status</h3>
        <div class="space-y-3">
            @foreach([
                ['queue' => 'sync',     'label' => 'Sync Queue',     'color' => 'indigo'],
                ['queue' => 'webhooks', 'label' => 'Webhooks Queue', 'color' => 'violet'],
                ['queue' => 'failed',  'label' => 'Failed Jobs',    'color' => 'red'],
            ] as $q)
            <div class="flex items-center justify-between">
                <span class="text-sm text-gray-600">{{ $q['label'] }}</span>
                <span class="badge bg-{{ $q['color'] }}-100 text-{{ $q['color'] }}-800">
                    {{ $queues[$q['queue']] ?? 0 }}
                </span>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Last sync times --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-gray-700 mb-4">Last Sync</h3>
        <div class="space-y-2.5 text-sm">
            @foreach(['products', 'inventory', 'orders', 'customers', 'amazon_orders'] as $type)
            @php $state = $syncState[$type] ?? null; @endphp
            <div class="flex items-center justify-between">
                <span class="text-gray-500 capitalize">{{ str_replace('_', ' ', $type) }}</span>
                <div class="text-right">
                    @if($state?->last_poll_at)
                        <span class="text-gray-700 font-medium">{{ $state->last_poll_at->diffForHumans() }}</span>
                    @else
                        <span class="text-gray-400">Never</span>
                    @endif
                    @if($state?->is_running)
                        <span class="ml-1 badge bg-blue-100 text-blue-700">running</span>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ── Chart + recent failures ── --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-6">

    {{-- Activity chart --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <h3 class="text-sm font-semibold text-gray-700 mb-4">Sync Activity — Last 7 Days</h3>
        <canvas id="activityChart" height="160"></canvas>
    </div>

    {{-- Recent failures --}}
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-gray-700">Recent Failures</h3>
            @if(auth()->user()->can('view-logs'))
            <a href="{{ route('dashboard.logs', ['status' => 'failed']) }}"
               class="text-xs text-indigo-600 hover:underline">View all →</a>
            @endif
        </div>
        @forelse($recentFailures as $log)
        <div class="flex items-start gap-3 py-2 border-b border-gray-50 last:border-0">
            <div class="w-1.5 h-1.5 mt-1.5 bg-red-500 rounded-full shrink-0"></div>
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2">
                    <span class="badge bg-gray-100 text-gray-700">{{ $log->entity_type }}</span>
                    <span class="text-xs text-gray-500">#{{ $log->entity_id }}</span>
                </div>
                <p class="text-xs text-red-600 truncate mt-0.5">{{ Str::limit($log->error_message, 70) }}</p>
                <p class="text-xs text-gray-400">{{ $log->created_at->diffForHumans() }}</p>
            </div>
        </div>
        @empty
        <p class="text-sm text-gray-400 text-center py-6">No recent failures 🎉</p>
        @endforelse
    </div>
</div>

{{-- ── Recent webhooks ── --}}
@if(auth()->user()->can('view-webhooks'))
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-5">
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-sm font-semibold text-gray-700">Recent Webhooks</h3>
        <a href="{{ route('dashboard.webhooks') }}" class="text-xs text-indigo-600 hover:underline">View all →</a>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-gray-500 border-b border-gray-100">
                    <th class="pb-2 font-medium">Topic</th>
                    <th class="pb-2 font-medium">Webhook ID</th>
                    <th class="pb-2 font-medium">Status</th>
                    <th class="pb-2 font-medium">Received</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($recentWebhooks as $wh)
                <tr class="hover:bg-gray-50">
                    <td class="py-2"><span class="badge bg-violet-100 text-violet-700">{{ $wh->topic }}</span></td>
                    <td class="py-2 text-gray-500 font-mono text-xs">{{ Str::limit($wh->shopify_webhook_id ?? $wh->webhook_id ?? '', 18) }}</td>
                    <td class="py-2">
                        @if($wh->processed)
                            <span class="badge bg-green-100 text-green-700">processed</span>
                        @elseif($wh->processing_error)
                            <span class="badge bg-red-100 text-red-700">error</span>
                        @else
                            <span class="badge bg-yellow-100 text-yellow-700">pending</span>
                        @endif
                    </td>
                    <td class="py-2 text-gray-400 text-xs">{{ $wh->created_at->diffForHumans() }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="py-6 text-center text-gray-400">No webhooks yet</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endif

@endsection

@push('scripts')
<script>
const ctx = document.getElementById('activityChart');
if (ctx) {
    const data = @json($chartData);
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: data.map(d => d.date),
            datasets: [
                { label: 'Success', data: data.map(d => d.success), backgroundColor: '#22c55e', borderRadius: 4 },
                { label: 'Failed',  data: data.map(d => d.failed),  backgroundColor: '#ef4444', borderRadius: 4 },
            ]
        },
        options: {
            responsive: true,
            plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 11 } } } },
            scales: {
                x: { stacked: true, grid: { display: false }, ticks: { font: { size: 11 } } },
                y: { stacked: true, beginAtZero: true, ticks: { font: { size: 11 }, stepSize: 1 } }
            }
        }
    });
}
</script>
@endpush
