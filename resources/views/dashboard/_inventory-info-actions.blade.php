<form method="POST" action="{{ route('dashboard.inventory.fetch-stock-single', $erpId) }}" class="inline">
    @csrf
    <button class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">↓ Fetch Stock</button>
</form>
<form method="POST" action="{{ route('dashboard.inventory.post-stock-single', $erpId) }}" class="inline">
    @csrf
    <button class="px-3 py-1.5 text-xs bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">↑ Post Stock</button>
</form>
