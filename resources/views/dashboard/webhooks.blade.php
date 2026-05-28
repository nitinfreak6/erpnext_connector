@extends('dashboard.layout')
@section('title', 'Webhooks')
@section('page-title', 'Webhook Logs')

@section('content')

{{-- Summary --}}
<div class="grid grid-cols-4 gap-4 mb-4">
    @foreach([
        ['label' => 'Total',     'value' => $summary['total'],     'color' => 'gray'],
        ['label' => 'Processed', 'value' => $summary['processed'], 'color' => 'green'],
        ['label' => 'Pending',   'value' => $summary['pending'],   'color' => 'yellow'],
        ['label' => 'Errors',    'value' => $summary['errors'],    'color' => 'red'],
    ] as $s)
    <div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 flex items-center gap-3">
        <div class="text-2xl font-bold text-{{ $s['color'] }}-600">{{ number_format($s['value']) }}</div>
        <div class="text-sm text-gray-500">{{ $s['label'] }}</div>
    </div>
    @endforeach
</div>

{{-- Filters --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ $search }}" placeholder="Webhook ID, shop domain…"
                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm w-56 focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Topic</label>
            <select name="topic" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="">All Topics</option>
                @foreach($topics as $t)
                <option value="{{ $t }}" {{ $topic === $t ? 'selected' : '' }}>{{ $t }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Status</label>
            <select name="processed" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="">All</option>
                <option value="1" {{ $processed === '1' ? 'selected' : '' }}>Processed</option>
                <option value="0" {{ $processed === '0' ? 'selected' : '' }}>Pending</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">From Date</label>
            <input type="date" name="date_from" value="{{ request('date_from') }}"
                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <button type="submit" class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-indigo-700">Filter</button>
        <a href="{{ route('dashboard.webhooks') }}" class="text-sm text-gray-500 py-1.5">Reset</a>
    </form>
</div>

{{-- Table --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-medium text-gray-700">{{ $webhooks->total() }} webhooks</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left font-medium">Topic</th>
                    <th class="px-4 py-3 text-left font-medium">{{ $ecomDisplayName }} Webhook ID</th>
                    <th class="px-4 py-3 text-left font-medium">Shop Domain</th>
                    <th class="px-4 py-3 text-left font-medium">HMAC</th>
                    <th class="px-4 py-3 text-left font-medium">Status</th>
                    <th class="px-4 py-3 text-left font-medium">Error</th>
                    <th class="px-4 py-3 text-left font-medium">Received</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($webhooks as $wh)
                <tr class="hover:bg-gray-50 {{ $wh->processing_error ? 'bg-red-50/30' : '' }}"
                    x-data="{ expanded: false }">
                    <td class="px-4 py-2.5">
                        <span class="badge bg-violet-100 text-violet-700 text-xs">{{ $wh->topic }}</span>
                    </td>
                    <td class="px-4 py-2.5 font-mono text-xs text-gray-600">
                        {{ Str::limit($wh->shopify_webhook_id, 24) }}
                    </td>
                    <td class="px-4 py-2.5 text-xs text-gray-600">{{ $wh->shop_domain }}</td>
                    <td class="px-4 py-2.5">
                        @if($wh->hmac_valid)
                            <span class="text-green-600 text-xs">✓ Valid</span>
                        @else
                            <span class="text-red-600 text-xs">✗ Invalid</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5">
                        @if($wh->processed)
                            <span class="badge bg-green-100 text-green-700 text-xs">processed</span>
                        @elseif($wh->processing_error)
                            <span class="badge bg-red-100 text-red-700 text-xs">failed</span>
                        @else
                            <span class="badge bg-yellow-100 text-yellow-700 text-xs">pending</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5 text-xs text-red-500 max-w-xs">
                        <span class="truncate block" style="max-width:180px" title="{{ $wh->processing_error }}">
                            {{ Str::limit($wh->processing_error, 50) }}
                        </span>
                    </td>
                    <td class="px-4 py-2.5 text-xs text-gray-400 whitespace-nowrap">
                        {{ $wh->created_at->format('M j, H:i:s') }}
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="px-4 py-12 text-center text-gray-400">No webhooks received yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100">{{ $webhooks->links() }}</div>
</div>
@endsection
