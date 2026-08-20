<?php

namespace App\Console\Commands;

use App\Services\Sync\ScheduledSyncRunner;
use Illuminate\Console\Command;

class SyncDispatch extends Command
{
    protected $signature = 'sync:dispatch
                            {--dry-run : Print without syncing}';

    protected $description = 'Sync dispatch (fetch + post) using dashboard UI logic and global settings.';

    public function handle(ScheduledSyncRunner $runner): int
    {
        if ($this->option('dry-run')) {
            $this->info('Dry run — would run fetchDispatch + postDispatch per sales order direction.');

            return self::SUCCESS;
        }

        $result = $runner->runDispatch();
        $this->outputResult('dispatch', $result);

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
