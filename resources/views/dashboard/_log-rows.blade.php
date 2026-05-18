{{-- Reusable log rows partial --}}
<div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead class="text-xs text-gray-500 border-b border-gray-100">
            <tr>
                <th class="text-left pb-2 font-medium">Direction</th>
                <th class="text-left pb-2 font-medium">Entity</th>
                <th class="text-left pb-2 font-medium">ID</th>
                <th class="text-left pb-2 font-medium">Action</th>
                <th class="text-left pb-2 font-medium">Status</th>
                <th class="text-left pb-2 font-medium">Error</th>
                <th class="text-left pb-2 font-medium">When</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-50">
            @forelse($logs as $log)
            <tr class="hover:bg-gray-50">
                <td class="py-2">
                    @if($log->direction === 'odoo_to_shopify')
                        <span class="badge bg-blue-100 text-blue-700">{{ $erpDisplayName }} → Out</span>
                    @else
                        <span class="badge bg-purple-100 text-purple-700">In → {{ $erpDisplayName }}</span>

                    @endif
                </td>
                <td class="py-2"><span class="badge bg-gray-100 text-gray-600">{{ $log->entity_type }}</span></td>
                <td class="py-2 font-mono text-xs text-gray-600">#{{ $log->entity_id }}</td>
                <td class="py-2 text-gray-600 capitalize">{{ $log->action }}</td>
                <td class="py-2">
                    @php $colors = ['success'=>'green','failed'=>'red','pending'=>'yellow','processing'=>'blue','skipped'=>'gray']; $c = $colors[$log->status] ?? 'gray'; @endphp
                    <span class="badge bg-{{ $c }}-100 text-{{ $c }}-700">{{ $log->status }}</span>
                </td>
                <td class="py-2 text-xs text-red-500 max-w-xs truncate">
                    {{ Str::limit($log->error_message, 50) }}
                </td>
                <td class="py-2 text-xs text-gray-400">{{ $log->created_at->diffForHumans() }}</td>
            </tr>
            @empty
            <tr><td colspan="7" class="py-6 text-center text-gray-400">No logs yet</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
