<tr>
    <td colspan="8" class="px-4 py-16 text-center">
        <svg class="w-10 h-10 text-gray-200 mx-auto mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/>
        </svg>
        <p class="text-sm text-gray-400 font-medium">No customers found</p>
        <p class="text-xs text-gray-300 mt-1">
            @if($syncMode === 'erp_to_ecom')
                Click <strong>Fetch from {{ $erpDisplayName }}</strong> to import customers
            @elseif($syncMode === 'ecom_to_erp')
                Click <strong>Fetch from {{ $ecomDisplayName }}</strong> to import customers
            @else
                Use the buttons above to sync customers
            @endif
        </p>
    </td>
</tr>
