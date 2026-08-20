<?php

namespace App\Services\Sync;

use App\Http\Controllers\Dashboard\CustomersController;
use App\Http\Controllers\Dashboard\InventoryController;
use App\Http\Controllers\Dashboard\OrdersController;
use App\Http\Controllers\Dashboard\ProductsController;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Runs the same fetch + post flows as the dashboard UI, driven by global sync settings.
 *
 * Order: products → inventory → customers → orders → dispatch
 */
class ScheduledSyncRunner
{
    public function __construct(
        private readonly SettingsService $settings,
    ) {}

    /**
     * @return array<string, array{level: string, message: string, steps?: list<array>}>
     */
    public function runAll(?string $only = null): array
    {
        $pipeline = [
            'products'  => fn () => $this->runProducts(),
            'inventory' => fn () => $this->runInventory(),
            'customers' => fn () => $this->runCustomers(),
            'orders'    => fn () => $this->runOrders(),
            'dispatch'  => fn () => $this->runDispatch(),
        ];

        if ($only !== null && isset($pipeline[$only])) {
            return [$only => $pipeline[$only]()];
        }

        $results = [];
        foreach ($pipeline as $name => $run) {
            if ($only !== null && $name !== $only) {
                continue;
            }
            try {
                $results[$name] = $run();
            } catch (\Throwable $e) {
                Log::error("Scheduled sync [{$name}] failed: " . $e->getMessage(), ['exception' => $e]);
                $results[$name] = [
                    'level'   => 'error',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }

    /** @return array{level: string, message: string, steps?: list<array>} */
    public function runProducts(): array
    {
        if (!$this->settings->isProductSyncEnabled()) {
            return $this->skipped('Product sync is disabled in settings.');
        }

        $mode = $this->settings->productSyncMode();
        if ($mode === 'disabled') {
            return $this->skipped('Product sync mode is disabled.');
        }

        $steps = [];

        if ($mode === 'erp_to_ecom' || $mode === 'bidirectional') {
            $steps[] = $this->invoke(ProductsController::class, 'fetch');
            $steps[] = $this->invoke(ProductsController::class, 'postAll', ['direction' => 'erp_to_ecom']);
        }

        if ($mode === 'ecom_to_erp' || $mode === 'bidirectional') {
            $steps[] = $this->invoke(ProductsController::class, 'pull');
            $steps[] = $this->invoke(ProductsController::class, 'postAll', ['direction' => 'ecom_to_erp']);
        }

        return $this->summarize('Products', $steps);
    }

    /** @return array{level: string, message: string, steps?: list<array>} */
    public function runInventory(): array
    {
        if (!$this->settings->isInventorySyncEnabled()) {
            return $this->skipped('Inventory sync is disabled in settings.');
        }

        $steps = [
            $this->invoke(InventoryController::class, 'fetchStock'),
            $this->invoke(InventoryController::class, 'postStock'),
        ];

        return $this->summarize('Inventory', $steps);
    }

    /** @return array{level: string, message: string, steps?: list<array>} */
    public function runCustomers(): array
    {
        if (!$this->settings->isCustomerSyncEnabled()) {
            return $this->skipped('Customer sync is disabled in settings.');
        }

        $mode = $this->settings->customerSyncMode();
        if ($mode === 'disabled') {
            return $this->skipped('Customer sync mode is disabled.');
        }

        $steps = [];

        if ($mode === 'erp_to_ecom' || $mode === 'bidirectional') {
            $steps[] = $this->invoke(CustomersController::class, 'fetch');
            $steps[] = $this->invoke(CustomersController::class, 'postCustomers', ['direction' => 'erp_to_ecom']);
        }

        if ($mode === 'ecom_to_erp' || $mode === 'bidirectional') {
            $steps[] = $this->invoke(CustomersController::class, 'pull');
            $steps[] = $this->invoke(CustomersController::class, 'postCustomers', ['direction' => 'ecom_to_erp']);
        }

        return $this->summarize('Customers', $steps);
    }

    /** @return array{level: string, message: string, steps?: list<array>} */
    public function runOrders(): array
    {
        if (!$this->settings->isSalesOrderSyncEnabled()) {
            return $this->skipped('Sales order sync is disabled in settings.');
        }

        $mode = $this->settings->salesOrderSyncMode();
        if ($mode === 'disabled') {
            return $this->skipped('Sales order sync mode is disabled.');
        }

        $steps = [];

        if ($mode === 'erp_to_ecom' || $mode === 'bidirectional') {
            $steps[] = $this->invoke(OrdersController::class, 'fetch');
            $steps[] = $this->invoke(OrdersController::class, 'postSales', ['direction' => 'erp_to_ecom']);
        }

        if ($mode === 'ecom_to_erp' || $mode === 'bidirectional') {
            $steps[] = $this->invoke(OrdersController::class, 'pull');
            $steps[] = $this->invoke(OrdersController::class, 'postSales', ['direction' => 'ecom_to_erp']);
        }

        return $this->summarize('Orders', $steps);
    }

    /** @return array{level: string, message: string, steps?: list<array>} */
    public function runDispatch(): array
    {
        if (!$this->settings->isSalesOrderSyncEnabled()) {
            return $this->skipped('Dispatch skipped — sales order sync is disabled.');
        }

        if (!$this->settings->allowsDispatchFetch()) {
            return $this->skipped('Dispatch is not available for the current sales order sync direction.');
        }

        $mode = $this->settings->salesOrderSyncMode();
        $steps = [];

        if ($mode === 'erp_to_ecom' || $mode === 'bidirectional') {
            $steps[] = $this->invoke(OrdersController::class, 'fetchDispatch', ['direction' => 'erp_to_ecom']);
            $steps[] = $this->invoke(OrdersController::class, 'postDispatch', ['direction' => 'erp_to_ecom']);
        }

        if ($mode === 'ecom_to_erp' || $mode === 'bidirectional') {
            $steps[] = $this->invoke(OrdersController::class, 'fetchDispatch', ['direction' => 'ecom_to_erp']);
            $steps[] = $this->invoke(OrdersController::class, 'postDispatch', ['direction' => 'ecom_to_erp']);
        }

        if ($steps === []) {
            return $this->skipped('Dispatch sync mode is disabled.');
        }

        return $this->summarize('Dispatch', $steps);
    }

    /**
     * Invoke a dashboard controller action exactly as the UI AJAX buttons do.
     *
     * @param  class-string  $controller
     * @return array{level: string, message: string}
     */
    private function invoke(string $controller, string $method, array $input = []): array
    {
        $request = Request::create('/internal/scheduled-sync', 'POST', $input);
        $request->headers->set('Accept', 'application/json');
        $request->headers->set('X-Requested-With', 'XMLHttpRequest');

        /** @var JsonResponse|\Illuminate\Http\RedirectResponse $response */
        $response = app()->call([app($controller), $method], ['request' => $request]);

        if ($response instanceof JsonResponse) {
            $data = $response->getData(true);

            return [
                'level'   => $data['level'] ?? ($response->getStatusCode() >= 400 ? 'error' : 'success'),
                'message' => $data['message'] ?? '',
            ];
        }

        return ['level' => 'success', 'message' => 'OK'];
    }

    /** @param  list<array{level: string, message: string}>  $steps */
    private function summarize(string $label, array $steps): array
    {
        if ($steps === []) {
            return $this->skipped("{$label}: nothing to run for current sync direction.");
        }

        $hasError   = false;
        $hasWarning = false;
        $messages   = [];

        foreach ($steps as $step) {
            $level = $step['level'] ?? 'success';
            if ($level === 'error') {
                $hasError = true;
            }
            if ($level === 'warning') {
                $hasWarning = true;
            }
            if (($step['message'] ?? '') !== '') {
                $messages[] = $step['message'];
            }
        }

        $level = $hasError ? 'error' : ($hasWarning ? 'warning' : 'success');

        return [
            'level'   => $level,
            'message' => $label . ': ' . implode(' | ', array_unique($messages)),
            'steps'   => $steps,
        ];
    }

    /** @return array{level: string, message: string} */
    private function skipped(string $message): array
    {
        return ['level' => 'skipped', 'message' => $message];
    }
}
