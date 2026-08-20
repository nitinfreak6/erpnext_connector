<?php

namespace App\Services;

use App\Models\AlertNotification;
use App\Models\ProductCache;
use App\Models\SyncLog;
use App\Models\SyncMapping;
use App\Services\Sync\SyncEntityState;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AlertNotificationService
{
    const PENDING_HOURS = 8;

    /**
     * Run all checks and send emails.
     * - System alerts  → always run, use alert_email from connector_settings
     * - Custom alerts  → run only if active + send_to set in alert_notifications table
     */
    public function runAll(): void
    {
        // ── System alert email (single address for all 7 system alerts) ───
        $systemEmail = \App\Models\ConnectorSetting::where('key', 'alert_email')
            ->value('value') ?? '';

        $this->checkPendingOrders($systemEmail);
        $this->checkPendingDispatch($systemEmail);
        $this->checkPendingPurchaseOrders($systemEmail);
        $this->checkPendingProducts($systemEmail);
        $this->checkPendingCustomers($systemEmail);
        $this->checkPendingStockSync($systemEmail);
        $this->checkPhpErrors($systemEmail);

        // ── Custom notification alerts (DB-managed) ───────────────────────
        $customAlerts = AlertNotification::where('status', AlertNotification::STATUS_ACTIVE)
            ->whereNotNull('send_to')
            ->where('send_to', '!=', '')
            ->get();

        foreach ($customAlerts as $alert) {
            match ($alert->alert_type) {
                AlertNotification::TYPE_SALES_ORDER_CANCELLATION   => $this->runCustomAlert($alert),
                AlertNotification::TYPE_STOCK_SYNC_ZERO_COST        => $this->runCustomAlert($alert),
                AlertNotification::TYPE_UNABLE_TO_FETCH_STOCK       => $this->runCustomAlert($alert),
                AlertNotification::TYPE_SALES_ORDER_UNDER_DISPATCH  => $this->runCustomAlert($alert),
                default => null,
            };
        }
    }

    // =========================================================================
    // SYSTEM ALERTS — always run, email from connector_settings.alert_email
    // =========================================================================

    private function checkPendingOrders(string $systemEmail): void
    {
        $cutoff = now()->subHours(self::PENDING_HOURS);

        $rows = SyncMapping::whereIn('entity_type', ['order', 'sales_order'])
            ->where('ecom_status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->get();

        if ($rows->isEmpty()) return;

        $tableRows = $rows->map(function ($m) {
            $reason = $this->getPendingReason('order', $m->erp_id);
            return "<tr>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->erp_id}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->ecom_id}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->erp_reference}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->created_at->diffForHumans()}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;color:#dc2626;'>{$reason}</td>
            </tr>";
        })->implode('');

        $body = $this->systemEmailTemplate(
            'Pending Orders Alert',
            'The following orders have been pending for more than ' . self::PENDING_HOURS . ' hours and require attention:',
            $this->wrapTable(['ERP ID', 'Ecom ID', 'Reference', 'Pending Since', 'Reason'], $tableRows)
        );

        $this->sendSystem($systemEmail, 'Pending Orders Alert (' . count($rows) . ' items)', $body);
    }

    private function checkPendingDispatch(string $systemEmail): void
    {
        $cutoff = now()->subHours(self::PENDING_HOURS);

        $rows = SyncMapping::where('entity_type', 'dispatch')
            ->whereIn('ecom_status', SyncEntityState::DISPATCH_PUSHABLE)
            ->where('created_at', '<=', $cutoff)
            ->get();

        if ($rows->isEmpty()) return;

        $tableRows = $rows->map(function ($m) {
            $reason = $this->getPendingReason('dispatch', $m->erp_id);
            return "<tr>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->erp_id}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->ecom_id}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->created_at->diffForHumans()}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;color:#dc2626;'>{$reason}</td>
            </tr>";
        })->implode('');

        $body = $this->systemEmailTemplate(
            'Pending Dispatch Alert',
            'The following dispatches have been pending for more than ' . self::PENDING_HOURS . ' hours after dispatch confirmation was received:',
            $this->wrapTable(['ERP ID', 'Ecom ID', 'Pending Since', 'Reason'], $tableRows)
        );

        $this->sendSystem($systemEmail, 'Pending Dispatch Alert (' . count($rows) . ' items)', $body);
    }

    private function checkPendingPurchaseOrders(string $systemEmail): void
    {
        $cutoff = now()->subHours(self::PENDING_HOURS);

        $rows = SyncMapping::where('entity_type', 'purchase_order')
            ->where('ecom_status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->get();

        if ($rows->isEmpty()) return;

        $tableRows = $rows->map(function ($m) {
            $reason = $this->getPendingReason('purchase_order', $m->erp_id);
            return "<tr>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->erp_id}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->erp_reference}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->created_at->diffForHumans()}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;color:#dc2626;'>{$reason}</td>
            </tr>";
        })->implode('');

        $body = $this->systemEmailTemplate(
            'Pending Purchase Orders Alert',
            'The following purchase orders have been pending for more than ' . self::PENDING_HOURS . ' hours:',
            $this->wrapTable(['ERP ID', 'Reference', 'Pending Since', 'Reason'], $tableRows)
        );

        $this->sendSystem($systemEmail, 'Pending Purchase Orders Alert (' . count($rows) . ' items)', $body);
    }

    private function checkPendingProducts(string $systemEmail): void
    {
        $col    = ProductCache::ecomStatusColumn();
        $erpCol = ProductCache::erpIdColumn();

        $rows = ProductCache::where(function ($q) use ($col) {
                $q->where($col, ProductCache::STATUS_PENDING)
                  ->orWhere($col, ProductCache::STATUS_FAILED)
                  ->orWhereNull($col);
            })
            ->where('fetched_at', '<=', now()->subHours(self::PENDING_HOURS))
            ->get();

        if ($rows->isEmpty()) return;

        $tableRows = $rows->map(function ($p) use ($erpCol) {
            $erpId  = $p->$erpCol;
            $status = $p->ecom_status ?? 'not pushed';
            $reason = $p->ecom_message ?? ($status === ProductCache::STATUS_FAILED ? 'Push failed — check sync logs' : 'Not yet pushed to ecom');
            return "<tr>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$erpId}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$p->name}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$p->default_code}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$status}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;color:#dc2626;'>{$reason}</td>
            </tr>";
        })->implode('');

        $body = $this->systemEmailTemplate(
            'Pending Products Sync Alert',
            'The following products are pending sync and have not been pushed:',
            $this->wrapTable(['ERP ID', 'Name', 'SKU', 'Status', 'Reason'], $tableRows)
        );

        $this->sendSystem($systemEmail, 'Pending Products Sync Alert (' . count($rows) . ' items)', $body);
    }

    private function checkPendingCustomers(string $systemEmail): void
    {
        $rows = SyncMapping::where('entity_type', 'customer')
            ->where('ecom_status', 'pending')
            ->where('created_at', '<=', now()->subHours(self::PENDING_HOURS))
            ->get();

        if ($rows->isEmpty()) return;

        $tableRows = $rows->map(function ($m) {
            $reason = $this->getPendingReason('customer', $m->erp_id);
            return "<tr>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->erp_id}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->ecom_id}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->created_at->diffForHumans()}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;color:#dc2626;'>{$reason}</td>
            </tr>";
        })->implode('');

        $body = $this->systemEmailTemplate(
            'Pending Customers Sync Alert',
            'The following customers are pending sync:',
            $this->wrapTable(['ERP ID', 'Ecom ID', 'Pending Since', 'Reason'], $tableRows)
        );

        $this->sendSystem($systemEmail, 'Pending Customers Sync Alert (' . count($rows) . ' items)', $body);
    }

    private function checkPendingStockSync(string $systemEmail): void
    {
        $rows = SyncMapping::where('entity_type', 'inventory')
            ->where('ecom_status', 'pending')
            ->where('created_at', '<=', now()->subHours(self::PENDING_HOURS))
            ->get();

        if ($rows->isEmpty()) return;

        $tableRows = $rows->map(function ($m) {
            $qty    = $m->metadata['qty_on_hand'] ?? $m->metadata['quantity'] ?? '—';
            $sku    = $m->metadata['default_code'] ?? $m->metadata['product_default_code'] ?? '—';
            $reason = $this->getPendingReason('inventory', $m->erp_id);
            return "<tr>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->erp_id}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$sku}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$qty}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$m->created_at->diffForHumans()}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;color:#dc2626;'>{$reason}</td>
            </tr>";
        })->implode('');

        $body = $this->systemEmailTemplate(
            'Pending Stock Sync Alert',
            'The following stock items are pending sync:',
            $this->wrapTable(['ERP ID', 'SKU', 'Qty', 'Pending Since', 'Reason'], $tableRows)
        );

        $this->sendSystem($systemEmail, 'Pending Stock Sync Alert (' . count($rows) . ' items)', $body);
    }

    private function checkPhpErrors(string $systemEmail): void
    {
        $rows = SyncLog::where('status', SyncLog::STATUS_FAILED)
            ->where('created_at', '>=', now()->subHour())
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        if ($rows->isEmpty()) return;

        $tableRows = $rows->map(function ($log) {
            $error = e(mb_substr($log->error_message ?? 'Unknown error', 0, 200));
            return "<tr>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$log->entity_type}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$log->entity_id}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$log->direction}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;'>{$log->created_at->format('d M Y H:i')}</td>
                <td style='padding:6px 12px;border:1px solid #e5e7eb;color:#dc2626;font-size:12px;'>{$error}</td>
            </tr>";
        })->implode('');

        $body = $this->systemEmailTemplate(
            'Application Error Alert',
            'The following errors were detected in the last hour:',
            $this->wrapTable(['Entity Type', 'Entity ID', 'Direction', 'Time', 'Error'], $tableRows)
        );

        $this->sendSystem($systemEmail, 'Application Error Alert (' . count($rows) . ' errors)', $body);
    }

    // =========================================================================
    // CUSTOM ALERTS — triggered from DB, email/subject/body all configurable
    // =========================================================================

    /**
     * Generic runner for custom notification alerts.
     * The alert body is already configured by the user — just send it as-is
     * (with an empty {body} replacement since these are event-triggered, not query-based).
     * To send with real data, call sendCustomAlert() directly from your jobs/services.
     */
    private function runCustomAlert(AlertNotification $alert): void
    {
        // Custom alerts are event-driven (triggered by specific jobs).
        // This method is intentionally a no-op for cron runs —
        // call sendCustomAlert() directly from the relevant job when the event occurs.
    }

    /**
     * Call this from jobs/services to send a custom notification alert.
     * e.g. $this->alertService->sendCustomAlert('sales_order_cancellation', $rowsHtml);
     */
    public function sendCustomAlert(string $alertType, string $rowsHtml): void
    {
        $alert = AlertNotification::where('alert_type', $alertType)
            ->where('status', AlertNotification::STATUS_ACTIVE)
            ->whereNotNull('send_to')
            ->where('send_to', '!=', '')
            ->first();

        if (!$alert) return;

        $body = $alert->buildBody($rowsHtml);
        $this->send($alert->send_to, $alert->cc ?? '', $alert->bcc ?? '', $alert->subject, $body);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function getPendingReason(string $entityType, ?string $erpId): string
    {
        if (!$erpId) return 'No ERP ID recorded';

        $log = SyncLog::where('entity_type', $entityType)
            ->where('entity_id', (string) $erpId)
            ->where('status', SyncLog::STATUS_FAILED)
            ->orderByDesc('created_at')
            ->first();

        if ($log && $log->error_message) {
            return e(mb_substr($log->error_message, 0, 150));
        }

        return 'No push attempted yet';
    }

    private function systemEmailTemplate(string $title, string $intro, string $tableHtml): string
    {
        return "
        <div style='font-family:sans-serif;font-size:14px;color:#374151;max-width:800px;'>
            <p>Hello Team,</p>
            <p>{$intro}</p>
            {$tableHtml}
            <br>
            <p>Kind regards<br><strong>b.solutions</strong></p>
        </div>";
    }

    private function wrapTable(array $headers, string $rows): string
    {
        $headerHtml = collect($headers)
            ->map(fn($h) => "<th style='padding:8px 12px;background:#f3f4f6;border:1px solid #e5e7eb;text-align:left;font-size:12px;font-weight:600;color:#374151;'>{$h}</th>")
            ->implode('');

        return "<table style='border-collapse:collapse;width:100%;font-size:13px;font-family:sans-serif;'>
            <thead><tr>{$headerHtml}</tr></thead>
            <tbody>{$rows}</tbody>
        </table>";
    }

    private function sendSystem(string $toRaw, string $subject, string $body): void
    {
        if (empty(trim($toRaw))) {
            Log::warning("AlertNotificationService: system alert_email is not configured. Subject: {$subject}");
            return;
        }
        $this->send($toRaw, '', '', $subject, $body);
    }

    private function send(string $toRaw, string $ccRaw, string $bccRaw, string $subject, string $body): void
    {
        try {
            $to  = $this->parseEmails($toRaw);
            $cc  = $this->parseEmails($ccRaw);
            $bcc = $this->parseEmails($bccRaw);

            if (empty($to)) return;

            Mail::html($body, function ($message) use ($to, $cc, $bcc, $subject) {
                $message->to($to)->subject($subject);
                if (!empty($cc))  $message->cc($cc);
                if (!empty($bcc)) $message->bcc($bcc);
            });

            Log::info("AlertNotificationService: sent \"{$subject}\" to " . implode(', ', $to));
        } catch (\Throwable $e) {
            Log::error("AlertNotificationService: failed to send \"{$subject}\": " . $e->getMessage());
        }
    }

    private function parseEmails(string $raw): array
    {
        return array_filter(
            array_map('trim', preg_split('/[,;]+/', $raw)),
            fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)
        );
    }
}