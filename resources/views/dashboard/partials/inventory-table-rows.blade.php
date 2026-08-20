@forelse($variants as $mapping)
    @include('dashboard.partials.inventory-table-row', [
        'mapping'         => $mapping,
        'syncMode'        => $syncMode,
        'erpDisplayName'  => $erpDisplayName,
        'ecomDisplayName' => $ecomDisplayName,
        'rowIndex'        => $loop->index,
    ])
@empty
    <tr>
        <td colspan="10" class="px-4 py-12 text-center text-gray-400 text-sm">
            No inventory records yet.
            <div class="text-xs text-gray-400 mt-2">Click <strong>Fetch</strong> to pull stock data.</div>
        </td>
    </tr>
@endforelse
