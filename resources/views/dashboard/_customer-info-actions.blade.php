<form method="POST" action="{{ route('dashboard.customers.fetch-single', $customerId) }}" class="inline">
    @csrf
    <button class="px-3 py-1.5 text-xs bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">↓ Fetch</button>
</form>
<form method="POST" action="{{ route('dashboard.customers.post-single', $customerId) }}" class="inline">
    @csrf
    <button class="px-3 py-1.5 text-xs bg-emerald-600 text-white rounded-lg hover:bg-emerald-700">↑ Post</button>
</form>
