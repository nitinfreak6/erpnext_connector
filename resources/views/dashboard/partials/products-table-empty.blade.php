<tr>
    <td colspan="8" class="px-4 py-16 text-center">
        <svg class="w-10 h-10 text-gray-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
        </svg>
        <p class="text-sm text-gray-400 font-medium">No products found</p>
        <p class="text-xs text-gray-300 mt-1">
            @if($syncMode === 'erp_to_ecom')
                Click <strong>Fetch from {{ $erpDisplayName }}</strong> to import products
            @elseif($syncMode === 'ecom_to_erp')
                Click <strong>Pull from {{ ucfirst($ecomDriver ?? 'ecom') }}</strong> to import products
            @else
                Use the buttons above to sync products
            @endif
        </p>
    </td>
</tr>
