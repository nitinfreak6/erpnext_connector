<?php

namespace App\Console\Commands;

use App\Services\Ecom\EcomInterface;
use App\Services\SettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Configure webhooks based on current sync direction settings.
 * 
 * This ensures webhooks are only active for directions that are enabled,
 * preventing unwanted data flow when sync direction changes.
 * 
 * Usage:
 *   php artisan webhooks:configure
 *   php artisan webhooks:configure --unregister-all
 */
class ConfigureWebhooksCommand extends Command
{
    protected $signature = 'webhooks:configure
                            {--unregister-all : Remove all webhooks regardless of settings}
                            {--list          : Show currently registered webhooks}
                            {--dry-run       : Show what would be done without making changes}';

    protected $description = 'Configure e-commerce webhooks based on sync direction settings';

    public function handle(SettingsService $settings, EcomInterface $ecom): int
    {
        $ecomDriver = $settings->ecomDriver();
        
        $this->info("Configuring webhooks for {$ecomDriver}...");
        $this->newLine();

        // ── List mode ────────────────────────────────────────────────────
        if ($this->option('list')) {
            return $this->listWebhooks($ecom);
        }

        // ── Unregister all mode ──────────────────────────────────────────
        if ($this->option('unregister-all')) {
            return $this->unregisterAll($ecom);
        }

        // ── Configure based on sync direction ────────────────────────────
        $productMode = $settings->productSyncMode();
        $orderMode   = $settings->salesOrderSyncMode();
        $customerMode = $settings->customerSyncMode();

        $this->table(['Setting', 'Value'], [
            ['Product Sync Mode', $productMode],
            ['Order Sync Mode', $orderMode],
            ['Customer Sync Mode', $customerMode],
        ]);
        $this->newLine();

        $topics = $this->determineRequiredWebhooks($productMode, $orderMode, $customerMode);

        if (empty($topics)) {
            $this->warn('No webhooks needed based on current sync direction settings.');
            $this->line('All sync directions are set to push FROM ecommerce, not receive FROM ecommerce.');
            return self::SUCCESS;
        }

        $this->info('Required webhooks based on sync direction:');
        foreach ($topics as $topic) {
            $this->line("  • {$topic}");
        }
        $this->newLine();

        if ($this->option('dry-run')) {
            $this->comment('[DRY RUN] Would register the above webhooks.');
            return self::SUCCESS;
        }

        // ── First, unregister all existing webhooks ──────────────────────
        $this->line('Clearing existing webhooks...');
        try {
            $ecom->unregisterAllWebhooks();
            $this->info('✓ Existing webhooks cleared');
        } catch (\Throwable $e) {
            $this->error('Failed to clear existing webhooks: ' . $e->getMessage());
            return self::FAILURE;
        }

        // ── Register required webhooks ───────────────────────────────────
        $this->line('Registering new webhooks...');
        try {
            $registered = $ecom->registerWebhooks($topics);
            
            $this->newLine();
            $this->info('✓ Successfully registered ' . count($registered) . ' webhook(s)');
            
            if (count($registered) > 0) {
                $this->table(
                    ['Topic', 'ID'],
                    collect($registered)->map(fn($w) => [
                        $w['topic'] ?? 'unknown',
                        $w['id'] ?? 'N/A'
                    ])
                );
            }

            Log::info('Webhooks configured', [
                'ecom_driver' => $ecomDriver,
                'topics' => $topics,
                'registered_count' => count($registered),
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to register webhooks: ' . $e->getMessage());
            Log::error('Webhook registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Determine which webhooks are needed based on sync direction.
     */
    private function determineRequiredWebhooks(
        string $productMode,
        string $orderMode,
        string $customerMode
    ): array {
        $topics = [];

        // ── Orders: Only listen if orders flow FROM ecom TO erp ─────────
        if (in_array($orderMode, ['ecom_to_erp', 'bidirectional'], true)) {
            $topics[] = 'orders/create';
            $topics[] = 'orders/updated';
        }

        // ── Products: Only listen if products flow FROM ecom TO erp ─────
        if (in_array($productMode, ['ecom_to_erp', 'bidirectional'], true)) {
            $topics[] = 'products/create';
            $topics[] = 'products/update';
        }

        // ── Inventory: Only listen if products flow FROM erp TO ecom ────
        // (We push inventory to ecom, so we need to know if it changes externally)
        if (in_array($productMode, ['erp_to_ecom', 'bidirectional'], true)) {
            $topics[] = 'inventory_levels/update';
        }

        // ── Customers: Only listen if customers flow FROM ecom TO erp ───
        if (in_array($customerMode, ['ecom_to_erp', 'bidirectional'], true)) {
            $topics[] = 'customers/create';
            $topics[] = 'customers/update';
        }

        return array_unique($topics);
    }

    private function listWebhooks(EcomInterface $ecom): int
    {
        try {
            $webhooks = $ecom->listWebhooks();
            
            if (empty($webhooks)) {
                $this->info('No webhooks currently registered.');
                return self::SUCCESS;
            }

            $this->info('Currently registered webhooks:');
            $this->table(
                ['ID', 'Topic', 'Address'],
                collect($webhooks)->map(fn($w) => [
                    $w['id'] ?? 'N/A',
                    $w['topic'] ?? 'unknown',
                    $w['address'] ?? 'N/A',
                ])
            );

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to list webhooks: ' . $e->getMessage());
            return self::FAILURE;
        }
    }

    private function unregisterAll(EcomInterface $ecom): int
    {
        if ($this->option('dry-run')) {
            $this->comment('[DRY RUN] Would unregister all webhooks.');
            return self::SUCCESS;
        }

        try {
            $this->warn('Unregistering ALL webhooks...');
            $ecom->unregisterAllWebhooks();
            $this->info('✓ All webhooks unregistered');
            
            Log::info('All webhooks unregistered via command');
            
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Failed to unregister webhooks: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}