@forelse($customers as $mapping)
    @include('dashboard.partials.customers-table-row', [
        'mapping'         => $mapping,
        'syncMode'        => $syncMode,
        'direction'       => $direction ?? 'erp_to_ecom',
        'erpDisplayName'  => $erpDisplayName,
        'ecomDisplayName' => $ecomDisplayName,
        'rowIndex'        => $loop->index,
    ])
@empty
    @include('dashboard.partials.customers-table-empty', compact('syncMode', 'erpDisplayName', 'ecomDisplayName'))
@endforelse
