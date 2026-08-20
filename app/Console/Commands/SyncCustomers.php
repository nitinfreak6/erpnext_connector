<?php

namespace App\Console\Commands;

use App\Services\Sync\ScheduledSyncRunner;
use Illuminate\Console\Command;

class SyncCustomers extends Command
{
    protected $signature = 'sync:customers
                            {--dry-run : Print without syncing}
                            {--force   : Ignored — kept for backward compatibility}';

    protected $description = 'Sync customers (fetch + post) using dashboard UI logic and global settings.';

    public function handle(ScheduledSyncRunner $runner): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run — would run customer fetch/pull + post per customer_sync_mode.');

            return self::SUCCESS;
        }

        $result = $runner->runCustomers();
        $this->outputResult('customers', $result);

        return ($result['level'] ?? '') === 'error' ? self::FAILURE : self::SUCCESS;
    }

    private function outputResult(string $entity, array $result): void
    {
        $level   = $result['level'] ?? 'info';
        $message = $result['message'] ?? '';

        match ($level) {
            'error'   => $this->error("[{$entity}] {$message}"),
            'warning' => $this->warn("[{$entity}] {$message}"),
            'skipped' => $this->line("[{$entity}] skipped — {$message}"),
            default   => $this->info("[{$entity}] {$message}"),
        };
    }
}
