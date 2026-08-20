@forelse($products as $product)
    @include('dashboard.partials.products-table-row', [
        'product'    => $product,
        'syncMode'   => $syncMode,
        'direction'  => $direction ?? 'erp_to_ecom',
        'ecomDriver' => $ecomDriver ?? null,
        'rowIndex'   => $loop->index,
    ])
@empty
    @include('dashboard.partials.products-table-empty', compact('syncMode', 'ecomDriver'))
@endforelse
