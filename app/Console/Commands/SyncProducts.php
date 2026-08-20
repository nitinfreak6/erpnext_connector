<?php

namespace App\Console\Commands;

use App\Services\Sync\ScheduledSyncRunner;
use Illuminate\Console\Command;

class SyncProducts extends Command
{
    protected $signature = 'sync:products
                            {--dry-run : Print state without dispatching}
                            {--force   : Ignored — kept for backward compatibility}';

    protected $description = 'Sync products (fetch + post) using dashboard UI logic and global settings.';

    public function handle(ScheduledSyncRunner $runner): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run — would run product fetch + post per product_sync_mode.');

            return self::SUCCESS;
        }

        $result = $runner->runProducts();
        $this->outputResult('products', $result);

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
