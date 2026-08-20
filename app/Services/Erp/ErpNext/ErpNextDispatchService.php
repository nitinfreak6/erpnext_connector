<?php

namespace App\Services\Erp\ErpNext;

use App\Services\FieldMappingService;
use App\Services\Sync\UniversalSyncService;

class ErpNextDispatchService
{
    public function __construct(
        private readonly ErpNextService $api,
        private readonly ErpNextOrderService $orders,
    ) {}

    private function sync(): UniversalSyncService
    {
        return app(UniversalSyncService::class);
    }

    private function mapper(): FieldMappingService
    {
        return app(FieldMappingService::class);
    }

    /** @return list<array<string, mixed>> */
    public function getFulfilledOrders(?string $sinceDate = null): array
    {
        $filters = [['docstatus', '=', 1], ['status', '=', 'Completed']];

        if ($sinceDate) {
            $filters[] = ['modified', '>', date('Y-m-d H:i:s', strtotime($sinceDate) ?: time())];
        }

        $fields = $this->api->fetchFieldsForEntity('dispatch', 'header', forList: true);

        $notes = $this->api->listDocs('Delivery Note', $filters, $fields, limit: 200);

        return array_map(fn ($n) => $this->normalizeDocument($n), $notes);
    }

    /**
     * @param  array<string, mixed>  $mappedPayload
     * @param  array<string, mixed>  $sourceFulfillment
     * @return array{picking_id: string, wire: list<array<string, mixed>>}
     */
    public function applyFulfillmentToSaleOrder(int|string $saleOrderId, array $mappedPayload, array $sourceFulfillment): array
    {
        $orderName = (string) $saleOrderId;
        $order     = $this->orders->getByName($orderName);
        if (!$order) {
            throw new \RuntimeException("Sales Order [{$orderName}] not found in ERPNext.");
        }

        $company     = $this->mapper()->configuredCompany('dispatch');
        $existing    = $this->orders->getDeliveryNotesForOrder($orderName);
        $shopifyLoc  = $this->orders->shopifyLocationFromFulfillment($sourceFulfillment);
        $warehouse   = $this->api->resolveWarehouse(null, $shopifyLoc, $company);
        $enrichment  = $this->dispatchEnrichment($warehouse, $company);
        $headerFields = $enrichment['header'];
        $lineFields  = $enrichment['line'];
        $linesKey    = $this->deliveryNoteLinesKey();
        $warehouseField = $this->mapper()->erpFieldForChannelMap(
            'warehouse',
            ['sales_order', 'dispatch'],
            'line',
        );

        if ($existing !== []) {
            $dn        = $existing[0];
            $dnName    = trim((string) ($dn['name'] ?? ''));
            $docstatus = (int) ($dn['docstatus'] ?? 0);

            if ($dnName === '') {
                throw new \RuntimeException(
                    "Delivery Note exists for Sales Order [{$orderName}] but ERPNext list response did not include its name."
                );
            }

            if ($docstatus === 0) {
                $this->refreshDraftDeliveryNote($dnName, $headerFields, $lineFields, $linesKey);
                $this->submitDeliveryNote($dnName, $warehouse, $linesKey, $warehouseField);

                return [
                    'picking_id' => $dnName,
                    'wire'       => $this->api->takeWireLog(),
                ];
            }

            return [
                'picking_id' => $dnName,
                'wire'       => $this->api->takeWireLog(),
            ];
        }

        $headerPayload = $this->sync()->extractHeaderWritePayload('dispatch', $mappedPayload, 'ecom_to_erp');

        $payload = array_merge([
            'doctype'  => 'Delivery Note',
            'customer' => $order['customer'] ?? null,
            $linesKey  => $this->orders->buildDeliveryNoteItemsFromSalesOrder(
                $order,
                $orderName,
                $lineFields,
            ),
        ], $headerFields, $headerPayload);

        $result = $this->api->insertDoc('Delivery Note', $payload);
        $dnName = trim((string) ($result['name'] ?? ''));

        if ($dnName === '') {
            throw new \RuntimeException(
                'Delivery Note was saved in ERPNext but the API did not return a document name.'
            );
        }

        $this->submitDeliveryNote($dnName, $warehouse, $linesKey, $warehouseField);

        return [
            'picking_id' => $dnName,
            'wire'       => $this->api->takeWireLog(),
        ];
    }

    /**
     * @return array{company: string, header: array<string, mixed>, line: array<string, mixed>}
     */
    private function dispatchEnrichment(string $warehouse, string $company): array
    {
        $mapper = $this->mapper();

        $context = [
            '_warehouse' => $warehouse,
            '_company'   => $company,
            'company'    => $company,
        ];

        $headerFields = $mapper->buildErpEnrichmentPayload('dispatch', 'header', $context);
        $headerFields['company'] = trim((string) ($headerFields['company'] ?? $company));

        $lineFields = array_merge(
            $mapper->buildErpEnrichmentPayload('sales_order', 'line', $context),
            $mapper->buildErpEnrichmentPayload('dispatch', 'line', $context),
        );

        $warehouseField = $mapper->erpFieldForChannelMap('warehouse', ['sales_order', 'dispatch'], 'line');
        if ($warehouseField !== null && $warehouse !== '') {
            $lineFields[$warehouseField] = $warehouse;
        }

        return [
            'company' => $headerFields['company'],
            'header'  => $headerFields,
            'line'    => $lineFields,
        ];
    }

    private function deliveryNoteLinesKey(): string
    {
        $container = $this->sync()->resolveLineContainer('dispatch', 'ecom_to_erp')
            ?? $this->sync()->resolveLineContainer('sales_order', 'ecom_to_erp');

        if ($container === null) {
            throw new \RuntimeException(
                'Delivery Note requires line_container field config (dispatch or sales_order header).'
            );
        }

        return $container['erp_lines_key'];
    }

    /** @param  array<string, mixed>  $headerFields
     * @param  array<string, mixed>  $lineFields
     */
    private function refreshDraftDeliveryNote(
        string $dnName,
        array $headerFields,
        array $lineFields,
        string $linesKey,
    ): void {
        $doc = $this->api->getDoc('Delivery Note', $dnName);
        if (!$doc || (int) ($doc['docstatus'] ?? 0) !== 0) {
            return;
        }

        $items   = $doc[$linesKey] ?? [];
        $changed = false;

        foreach ($headerFields as $key => $value) {
            if (($doc[$key] ?? null) === $value) {
                continue;
            }

            $doc[$key] = $value;
            $changed   = true;
        }

        foreach ($items as $index => $item) {
            if (!is_array($item)) {
                continue;
            }

            $lineChanged = false;

            foreach ($lineFields as $key => $value) {
                if (($item[$key] ?? null) === $value) {
                    continue;
                }

                $items[$index][$key] = $value;
                $lineChanged         = true;
            }

            if ($lineChanged) {
                $changed = true;
            }
        }

        if (!$changed) {
            return;
        }

        $doc[$linesKey] = $items;
        $this->api->callMethod('frappe.client.save', ['doc' => $doc]);
    }

    private function submitDeliveryNote(
        string $dnName,
        ?string $mappedWarehouse = null,
        ?string $linesKey = null,
        ?string $warehouseField = null,
    ): void {
        $linesKey ??= $this->deliveryNoteLinesKey();
        $warehouseField ??= $this->mapper()->erpFieldForChannelMap(
            'warehouse',
            ['sales_order', 'dispatch'],
            'line',
        );

        try {
            $this->api->callMethod('frappe.client.submit', [
                'doc' => $this->api->getDoc('Delivery Note', $dnName),
            ]);
        } catch (\Throwable $e) {
            if ($this->api->isNegativeStockError($e)) {
                $hint = $this->api->summarizeNegativeStockError($e);
                if ($mappedWarehouse !== null && $mappedWarehouse !== '') {
                    $hint .= " Connector warehouse is [{$mappedWarehouse}].";
                }

                $dnDoc = $this->api->getDoc('Delivery Note', $dnName);
                $dnWh  = '';
                if ($warehouseField !== null) {
                    $dnWh = trim((string) (($dnDoc[$linesKey][0][$warehouseField] ?? '') ?: ''));
                }
                if ($dnWh !== '' && $mappedWarehouse !== null && $dnWh !== $mappedWarehouse) {
                    $hint .= " Delivery Note line warehouse is [{$dnWh}] but connector mapped [{$mappedWarehouse}] — "
                        . 'cancel the draft DN, fix Mappings → Warehouse to match where stock exists, re-push inventory, then retry.';
                } elseif ($dnWh !== '' && $dnWh !== $mappedWarehouse) {
                    $hint .= " Delivery Note line warehouse is [{$dnWh}].";
                }

                $maps = app(\App\Services\ChannelMappingService::class);
                $mappingHint = method_exists($maps, 'soleActiveWarehouseOdooId')
                    ? $maps->soleActiveWarehouseOdooId()
                    : null;
                if ($mappingHint !== null && $mappingHint !== $mappedWarehouse) {
                    $hint .= " Mappings → Warehouse ERPNext ID is [{$mappingHint}].";
                }

                throw new \RuntimeException(
                    "Delivery Note [{$dnName}] saved as draft but submit failed: {$hint} "
                    . 'Ensure stock exists in the mapped ERPNext warehouse, re-push inventory from Shopify if needed, '
                    . 'then retry Post Dispatch (or cancel draft DN and retry).'
                );
            }

            throw new \RuntimeException('Delivery Note created but submit failed: ' . $e->getMessage());
        }
    }

    public function delete(string $deliveryNoteName): bool
    {
        return $this->api->deleteDoc('Delivery Note', $deliveryNoteName);
    }

    /** @param  array<string, mixed>  $note */
    public function normalizeDocument(array $note): array
    {
        $note['id']         = (string) ($note['name'] ?? '');
        $note['write_date'] = (string) ($note['modified'] ?? '');
        $note['state']      = (int) ($note['docstatus'] ?? 0) === 1 ? 'done' : 'draft';

        return $note;
    }

    /** @param  array<string, mixed>  $note */
    private function salesOrderFromDeliveryNote(array $note): string
    {
        $linkField = $this->sync()->dispatchSalesOrderLinkField('ecom_to_erp');
        if ($linkField === null) {
            return '';
        }

        $linesKey = $this->deliveryNoteLinesKey();

        foreach ($note[$linesKey] ?? [] as $line) {
            if (!is_array($line)) {
                continue;
            }

            $salesOrder = trim((string) ($line[$linkField] ?? ''));
            if ($salesOrder !== '') {
                return $salesOrder;
            }
        }

        return '';
    }
}
