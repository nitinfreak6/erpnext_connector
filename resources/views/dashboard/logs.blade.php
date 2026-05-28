@extends('dashboard.layout')
@section('title', 'Sync Logs')
@section('page-title', 'Sync Logs')

@section('content')

{{-- Summary badges --}}
<div class="flex flex-wrap gap-2 mb-4">
    @foreach(['success' => 'green', 'failed' => 'red', 'pending' => 'yellow', 'processing' => 'blue', 'skipped' => 'gray'] as $s => $c)
    <a href="{{ route('dashboard.logs', array_merge(request()->query(), ['status' => $s])) }}"
       class="badge bg-{{ $c }}-100 text-{{ $c }}-800 text-xs py-1 px-3 hover:ring-2 hover:ring-{{ $c }}-300 {{ $status === $s ? 'ring-2 ring-'.$c.'-400' : '' }}">
        {{ ucfirst($s) }} {{ $summary[$s] ?? 0 }}
    </a>
    @endforeach
    @if($status)
    <a href="{{ route('dashboard.logs', array_diff_key(request()->query(), ['status' => ''])) }}"
       class="badge bg-gray-100 text-gray-600 text-xs py-1 px-3 hover:bg-gray-200">× Clear filter</a>
    @endif
</div>

{{-- Filters --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm p-4 mb-4">
    <form method="GET" class="flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs text-gray-500 mb-1">Search</label>
            <input type="text" name="search" value="{{ $search }}" placeholder="Entity ID, error text…"
                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm w-52 focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Direction</label>
            <select name="direction" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="">All</option>
                <option value="erp_to_ecom" {{ in_array($direction, ['odoo_to_shopify','erp_to_ecom']) ? 'selected' : '' }}>{{ $erpDisplayName }} → {{ $ecomDisplayName }}</option>
                <option value="ecom_to_erp" {{ in_array($direction, ['shopify_to_odoo','ecom_to_erp']) ? 'selected' : '' }}>{{ $ecomDisplayName }} → {{ $erpDisplayName }}</option>
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Entity Type</label>
            <select name="entity_type" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="">All</option>
                @foreach($entityTypes as $type)
                <option value="{{ $type }}" {{ $entityType === $type ? 'selected' : '' }}>{{ $type }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">Status</label>
            <select name="status" class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
                <option value="">All</option>
                @foreach(['success', 'failed', 'pending', 'processing', 'skipped'] as $s)
                <option value="{{ $s }}" {{ $status === $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">From</label>
            <input type="date" name="date_from" value="{{ $dateFrom }}"
                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <div>
            <label class="block text-xs text-gray-500 mb-1">To</label>
            <input type="date" name="date_to" value="{{ $dateTo }}"
                   class="border border-gray-200 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-indigo-300 outline-none">
        </div>
        <button type="submit" class="bg-indigo-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-indigo-700">Filter</button>
        <a href="{{ route('dashboard.logs') }}" class="text-sm text-gray-500 py-1.5">Reset</a>
    </form>
</div>

{{-- Logs table --}}
<div class="bg-white rounded-xl border border-gray-100 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100">
        <span class="text-sm font-medium text-gray-700">{{ $logs->total() }} entries</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3 text-left font-medium">Direction</th>
                    <th class="px-4 py-3 text-left font-medium">Entity Type</th>
                    <th class="px-4 py-3 text-left font-medium">Entity ID</th>
                    <th class="px-4 py-3 text-left font-medium">Action</th>
                    <th class="px-4 py-3 text-left font-medium">Status</th>
                    <th class="px-4 py-3 text-left font-medium">Attempts</th>
                    <th class="px-4 py-3 text-left font-medium">Error</th>
                    <th class="px-4 py-3 text-left font-medium">Timestamp</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-50">
                @forelse($logs as $log)
                <tr class="hover:bg-gray-50 {{ $log->status === 'failed' ? 'bg-red-50/40' : '' }}">
                    <td class="px-4 py-2.5">
                        @if(in_array($log->direction, ['odoo_to_shopify','erp_to_ecom']))
                            <span class="badge bg-blue-100 text-blue-700 text-xs">{{ $erpDisplayName }} → {{ $ecomDisplayName }}</span>
                        @else
                            <span class="badge bg-purple-100 text-purple-700 text-xs">{{ $ecomDisplayName }} → {{ $erpDisplayName }}</span>
                        @endif
                    </td>
                    <td class="px-4 py-2.5"><span class="badge bg-gray-100 text-gray-600 text-xs">{{ $log->entity_type }}</span></td>
                    <td class="px-4 py-2.5 font-mono text-xs text-gray-700">#{{ $log->entity_id }}</td>
                    <td class="px-4 py-2.5 text-gray-600 capitalize text-xs">{{ $log->action }}</td>
                    <td class="px-4 py-2.5">
                        @php $colors = ['success'=>'green','failed'=>'red','pending'=>'yellow','processing'=>'blue','skipped'=>'gray']; $c = $colors[$log->status] ?? 'gray'; @endphp
                        <span class="badge bg-{{ $c }}-100 text-{{ $c }}-700 text-xs">{{ $log->status }}</span>
                    </td>
                    <td class="px-4 py-2.5 text-center text-xs text-gray-500">{{ $log->attempts }}</td>
                    <td class="px-4 py-2.5 text-xs text-red-500 max-w-xs">
                        <span class="truncate block" style="max-width:200px" title="{{ $log->error_message }}">
                            {{ Str::limit($log->error_message, 60) }}
                        </span>
                    </td>
                    <td class="px-4 py-2.5 text-xs text-gray-400 whitespace-nowrap">
                        {{ $log->created_at->format('M j, H:i') }}
                    </td>
                    <td class="px-4 py-2.5">
                        <a href="{{ route('dashboard.logs.show', $log) }}"
                           class="text-xs text-indigo-600 hover:underline whitespace-nowrap">Details →</a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="px-4 py-12 text-center text-gray-400">No logs match your filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="px-5 py-3 border-t border-gray-100">{{ $logs->links() }}</div>
</div>
@endsection