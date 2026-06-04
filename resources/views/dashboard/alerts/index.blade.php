@extends('dashboard.layout')
@section('page-title', 'Alerts & Notifications')

@section('content')

<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-bold text-gray-800 flex items-center gap-2">
        🔔 Alerts &amp; Notifications
    </h1>
    <div class="flex gap-3">
        <a href="{{ route('dashboard.alerts.create') }}"
           class="inline-flex items-center gap-1.5 bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm">
            + Add New Alert / Notification
        </a>
        <form method="POST" action="{{ route('dashboard.alerts.destroy') }}" id="bulk-delete-form">
            @csrf
            @method('DELETE')
            <div id="bulk-ids-container"></div>
            <button type="button" onclick="confirmBulkDelete()"
                    class="inline-flex items-center gap-1.5 bg-red-600 hover:bg-red-700 text-white px-4 py-2 rounded-lg text-sm font-medium transition shadow-sm">
                🗑 Delete Selected
            </button>
        </form>
    </div>
</div>

@if(session('success'))
    <div class="mb-4 px-4 py-3 bg-emerald-50 border border-emerald-200 text-emerald-700 rounded-lg text-sm">{{ session('success') }}</div>
@endif
@if(session('error'))
    <div class="mb-4 px-4 py-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm">{{ session('error') }}</div>
@endif

{{-- ── System Alerts (always on, read-only) ── --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden mb-6">
    <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between bg-gray-50">
        <div>
            <h2 class="text-sm font-semibold text-gray-700">System Alerts</h2>
            <p class="text-xs text-gray-400 mt-0.5">These alerts always run every hour automatically. Configure the recipient email in
                <a href="{{ route('dashboard.settings') }}" class="text-indigo-600 hover:underline">Settings → System Alert Email</a>.
            </p>
        </div>
        <span class="text-xs bg-emerald-100 text-emerald-700 px-2 py-1 rounded-full font-medium">Always On</span>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-100">
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Alert</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Trigger</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Send To</th>
                    <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                </tr>
            </thead>
            <tbody>
                @php
                    $systemAlerts = [
                        ['name' => 'Pending Orders',          'trigger' => 'Orders pending > 8 hours'],
                        ['name' => 'Pending Dispatch',        'trigger' => 'Dispatch pending > 8 hours after confirmation'],
                        ['name' => 'Pending Purchase Orders', 'trigger' => 'Purchase orders pending > 8 hours'],
                        ['name' => 'Pending Products Sync',   'trigger' => 'Products not pushed after 8 hours'],
                        ['name' => 'Pending Customers Sync',  'trigger' => 'Customers pending > 8 hours'],
                        ['name' => 'Pending Stock Sync',      'trigger' => 'Stock items pending > 8 hours'],
                        ['name' => 'PHP / Application Errors','trigger' => 'Failed sync logs in the last 1 hour'],
                    ];
                    $systemEmail = \App\Models\ConnectorSetting::where('key', 'alert_email')->value('value') ?? '';
                @endphp
                @foreach($systemAlerts as $sa)
                <tr class="border-b border-gray-50">
                    <td class="px-5 py-3 font-medium text-gray-800">{{ $sa['name'] }}</td>
                    <td class="px-5 py-3 text-gray-500 text-xs">{{ $sa['trigger'] }}</td>
                    <td class="px-5 py-3 text-gray-500 text-xs">
                        {{ $systemEmail ?: '—' }}
                        @if(!$systemEmail)
                            <a href="{{ route('dashboard.settings') }}" class="text-red-500 hover:underline ml-1">Set email →</a>
                        @endif
                    </td>
                    <td class="px-5 py-3">
                        <span class="px-2 py-1 bg-emerald-100 text-emerald-700 text-xs rounded-full font-medium">On</span>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- ── Custom Notification Alerts (DB-managed) ── --}}
<div class="bg-white rounded-xl border border-gray-200 shadow-sm overflow-hidden">
    <div class="px-5 py-3 border-b border-gray-100 bg-gray-50">
        <h2 class="text-sm font-semibold text-gray-700">Custom Notification Alerts</h2>
        <p class="text-xs text-gray-400 mt-0.5">Event-driven alerts triggered by specific sync operations. Configure email, subject and body per alert type.</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm" id="alerts-table">
            <thead>
                <tr class="border-b border-gray-100">
                    <th class="w-10 px-4 py-3">
                        <input type="checkbox" id="select-all" class="rounded border-gray-300" onchange="toggleAll(this)">
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Email Type</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Send To</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Subject</th>
                    <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Action</th>
                </tr>
                <tr class="bg-gray-50 border-b border-gray-100">
                    <td></td>
                    <td class="px-4 py-2"><input type="text" class="filter-input w-full border border-gray-200 rounded px-2 py-1 text-xs" data-col="0" placeholder="Filter…"></td>
                    <td class="px-4 py-2"><input type="text" class="filter-input w-full border border-gray-200 rounded px-2 py-1 text-xs" data-col="1" placeholder="Filter…"></td>
                    <td class="px-4 py-2"><input type="text" class="filter-input w-full border border-gray-200 rounded px-2 py-1 text-xs" data-col="2" placeholder="Filter…"></td>
                    <td class="px-4 py-2"><input type="text" class="filter-input w-full border border-gray-200 rounded px-2 py-1 text-xs" data-col="3" placeholder="Filter…"></td>
                    <td></td>
                </tr>
            </thead>
            <tbody>
                @forelse($alerts as $alert)
                <tr class="border-b border-gray-50 hover:bg-gray-50 alert-row">
                    <td class="px-4 py-3">
                        <input type="checkbox" value="{{ $alert->id }}" class="row-checkbox rounded border-gray-300">
                    </td>
                    <td class="px-4 py-3 font-medium text-gray-800">
                        {{ $typeLabels[$alert->alert_type] ?? $alert->alert_type }}
                    </td>
                    <td class="px-4 py-3">
                        <form method="POST" action="{{ route('dashboard.alerts.toggle', $alert) }}">
                            @csrf @method('PATCH')
                            <button type="submit"
                                    class="relative inline-flex items-center h-7 rounded-full w-14 transition-colors
                                           {{ $alert->isActive() ? 'bg-red-500' : 'bg-gray-300' }}">
                                <span class="inline-block w-5 h-5 bg-white rounded-full shadow transform transition-transform
                                             {{ $alert->isActive() ? 'translate-x-8' : 'translate-x-1' }}"></span>
                                <span class="absolute inset-0 flex items-center justify-center text-white text-xs font-bold">
                                    {{ $alert->isActive() ? 'On' : 'Off' }}
                                </span>
                            </button>
                        </form>
                    </td>
                    <td class="px-4 py-3 text-gray-500 text-xs">{{ $alert->send_to ?: '—' }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ Str::limit($alert->subject, 55) }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('dashboard.alerts.edit', $alert) }}"
                           class="text-gray-400 hover:text-indigo-600 transition" title="Edit">✏️</a>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="6" class="px-5 py-8 text-center text-gray-400 text-sm">
                        No custom alerts yet.
                        <a href="{{ route('dashboard.alerts.create') }}" class="text-indigo-600 hover:underline ml-1">Add one →</a>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<script>
function toggleAll(cb) {
    document.querySelectorAll('.row-checkbox').forEach(c => c.checked = cb.checked);
}

function confirmBulkDelete() {
    const checked = document.querySelectorAll('.row-checkbox:checked');
    if (!checked.length) { alert('Select at least one alert.'); return; }
    if (!confirm('Delete ' + checked.length + ' alert(s)?')) return;
    const container = document.getElementById('bulk-ids-container');
    container.innerHTML = '';
    checked.forEach(cb => {
        const i = document.createElement('input');
        i.type = 'hidden'; i.name = 'ids[]'; i.value = cb.value;
        container.appendChild(i);
    });
    document.getElementById('bulk-delete-form').submit();
}

document.querySelectorAll('.filter-input').forEach(input => {
    input.addEventListener('input', () => {
        const filters = {};
        document.querySelectorAll('.filter-input').forEach(i => filters[i.dataset.col] = i.value.toLowerCase());
        document.querySelectorAll('.alert-row').forEach(row => {
            const cells = row.querySelectorAll('td');
            let show = true;
            Object.entries(filters).forEach(([col, val]) => {
                if (val && !cells[parseInt(col)+1]?.textContent.toLowerCase().includes(val)) show = false;
            });
            row.style.display = show ? '' : 'none';
        });
    });
});
</script>

@endsection