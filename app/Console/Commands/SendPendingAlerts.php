<?php

namespace App\Console\Commands;

use App\Services\AlertNotificationService;
use Illuminate\Console\Command;

class SendPendingAlerts extends Command
{
    protected $signature   = 'alerts:send-pending {--debug : Show detailed check output}';
    protected $description = 'Check all pending sync states and send system alert emails. Custom notification alerts are event-driven.';

    public function handle(AlertNotificationService $service): int
    {
        $this->info('Running alert checks...');

        if ($this->option('debug')) {
            $this->runDebug();
        }

        try {
            $service->runAll();
            $this->info('Done.');
        } catch (\Throwable $e) {
            $this->error('Alert check failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function runDebug(): void
    {
        $this->line('');
        $this->line('<fg=yellow>── System alert email (connector_settings.alert_email) ─────</>');
        $email = \App\Models\ConnectorSetting::where('key', 'alert_email')->value('value') ?? '(not set)';
        $this->line("  alert_email = {$email}");

        $this->line('');
        $this->line('<fg=yellow>── Custom notification alerts (DB) ──────────────────────────</>');
        $alerts = \App\Models\AlertNotification::all();
        if ($alerts->isEmpty()) {
            $this->line('  (none)');
        }
        foreach ($alerts as $a) {
            $this->line("  [{$a->id}] {$a->alert_type} | status={$a->status} | send_to={$a->send_to}");
        }

        $this->line('');
        $this->line('<fg=yellow>── Pending rows (PENDING_HOURS=' . AlertNotificationService::PENDING_HOURS . ') ──────────────────</>');

        $cutoff = now()->subHours(AlertNotificationService::PENDING_HOURS);
        $this->line("  Cutoff: {$cutoff}");

        $orders = \App\Models\SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('ecom_status', 'pending')->where('created_at', '<=', $cutoff)->count();
        $dispatch = \App\Models\SyncMapping::where('entity_type', 'dispatch')
            ->where('ecom_status', 'pending_dispatch')->where('created_at', '<=', $cutoff)->count();
        $purchaseOrders = \App\Models\SyncMapping::where('entity_type', 'purchase_order')
            ->where('ecom_status', 'pending')->where('created_at', '<=', $cutoff)->count();
        $col = \App\Models\ProductCache::ecomStatusColumn();
        $products = \App\Models\ProductCache::where(fn($q) => $q
            ->where($col, 'pending')->orWhere($col, 'failed')->orWhereNull($col))
            ->where('fetched_at', '<=', $cutoff)->count();
        $customers = \App\Models\SyncMapping::where('entity_type', 'customer')
            ->where('ecom_status', 'pending')->where('created_at', '<=', $cutoff)->count();
        $stock = \App\Models\SyncMapping::where('entity_type', 'inventory')
            ->where('ecom_status', 'pending')->where('created_at', '<=', $cutoff)->count();
        $errors = \App\Models\SyncLog::where('status', 'failed')
            ->where('created_at', '>=', now()->subHour())->count();

        $this->table(
            ['Alert Type', 'Rows Found'],
            [
                ['Pending Orders',          $orders],
                ['Pending Dispatch',        $dispatch],
                ['Pending Purchase Orders', $purchaseOrders],
                ['Pending Products',        $products],
                ['Pending Customers',       $customers],
                ['Pending Stock',           $stock],
                ['PHP Errors (last 1hr)',   $errors],
            ]
        );

        $this->line('');
        $this->line('<fg=yellow>── Mail config ──────────────────────────────────────────────</>');
        $this->line('  driver: ' . config('mail.default'));
        $this->line('  from:   ' . config('mail.from.address'));
        $this->line('');
    }
}