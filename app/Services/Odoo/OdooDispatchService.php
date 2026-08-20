<?php

namespace App\Services\Odoo;

use App\Services\Sync\UniversalSyncService;
use Illuminate\Support\Facades\Log;

/**
 * Apply e-commerce fulfillment data to Odoo delivery pickings (E-com → ERP dispatch).
 * All field names come from dispatch field configs + Odoo fields_get introspection.
 */
class OdooDispatchService
{
    /** @var list<array<string, mixed>> */
    private array $wireLog = [];

    public function __construct(
        private readonly OdooService $odoo,
        private readonly UniversalSyncService $sync,
    ) {}

    /**
     * @return array{picking_id: int, wire: list<array<string, mixed>>}
     */
    public function applyFulfillmentToSaleOrder(int $saleOrderId, array $mappedPayload, array $sourceFulfillment): array
    {
        $this->wireLog = [];

        $picking = $this->findOutgoingPickingForSaleOrder($saleOrderId);

        if ($picking === null) {
            $this->confirmSaleOrder($saleOrderId);
            $picking = $this->findOutgoingPickingForSaleOrder($saleOrderId);
        }

        if ($picking === null) {
            $this->fail(
                "No outgoing delivery picking found for sale order #{$saleOrderId}. "
                . 'Confirm the sale order in Odoo (Quotation → Sales Order) so a delivery is created, then retry.'
            );
        }

        $pickingId = (int) $picking['id'];
        $state     = $this->readPickingState($picking);

        if ($state === 'done') {
            $this->writePickingHeader($pickingId, $mappedPayload);

            return [
                'picking_id' => $pickingId,
                'wire'       => $this->wireLog,
            ];
        }

        if ($state !== null && !in_array($state, ['assigned', 'confirmed', 'waiting', 'partially_available'], true)) {
            $this->fail(
                "Delivery #{$pickingId} is in state \"{$state}\" and cannot be validated."
            );
        }

        $this->writePickingHeader($pickingId, $mappedPayload);
        $this->applyMoveLinesFromMappedPayload($pickingId, $mappedPayload);
        $this->validatePicking($pickingId);

        return [
            'picking_id' => $pickingId,
            'wire'       => $this->wireLog,
        ];
    }

    /** @return array<string, mixed>|null */
    private function findOutgoingPickingForSaleOrder(int $saleOrderId): ?array
    {
        $domain = $this->sync->buildOutgoingPickingSearchDomain($saleOrderId);
        if ($domain === []) {
            $this->fail(
                'Cannot locate delivery pickings: no sale.order link field found on stock.picking in Odoo.'
            );
        }

        $normalizer = app(OdooFieldNormalizer::class);
        $readFields = $normalizer->filterSearchReadFields(
            'stock.picking',
            $this->sync->buildDispatchPickingReadFields('ecom_to_erp')
        );

        $rows = $this->searchReadPickings($domain, $readFields);

        if ($rows === [] && count($domain) > 1) {
            $saleOnly = array_values(array_filter(
                $domain,
                fn ($clause) => is_array($clause) && ($clause[0] ?? '') !== 'picking_type_code'
            ));
            if ($saleOnly !== []) {
                $rows = $this->searchReadPickings($saleOnly, $readFields);
            }
        }

        if ($rows === []) {
            return null;
        }

        foreach ($rows as $row) {
            if (($this->readPickingState($row) ?? '') !== 'cancel') {
                return $row;
            }
        }

        return $rows[0];
    }

    /** @param  list<array{0: string, 1: string, 2: mixed}>  $domain
     * @param  list<string>  $readFields
     * @return list<array<string, mixed>>
     */
    private function searchReadPickings(array $domain, array $readFields): array
    {
        $rows = $this->odoo->searchRead(
            'stock.picking',
            $domain,
            $readFields,
            ['order' => 'id desc', 'limit' => 5]
        );

        $this->recordCall('search_read', 'stock.picking', [
            'domain'  => $domain,
            'fields'  => $readFields,
            'options' => ['order' => 'id desc', 'limit' => 5],
        ], $rows);

        return $rows;
    }

    private function confirmSaleOrder(int $saleOrderId): void
    {
        try {
            $result = $this->odoo->executeKw('sale.order', 'action_confirm', [[$saleOrderId]]);
            $this->recordCall('execute_kw', 'sale.order', [
                'method' => 'action_confirm',
                'args'   => [[$saleOrderId]],
            ], $result);
            Log::info("OdooDispatchService: confirmed sale order #{$saleOrderId} to create delivery picking.");
        } catch (\Throwable $e) {
            $this->recordCall('execute_kw', 'sale.order', [
                'method' => 'action_confirm',
                'args'   => [[$saleOrderId]],
            ], ['error' => $e->getMessage()]);
            Log::debug("OdooDispatchService: action_confirm for sale#{$saleOrderId}: " . $e->getMessage());
        }
    }

    /** @param  array<string, mixed>  $mappedPayload */
    private function writePickingHeader(int $pickingId, array $mappedPayload): void
    {
        $container = $this->sync->resolveLineContainer('dispatch', 'ecom_to_erp');
        if ($container !== null) {
            unset($mappedPayload[$container['erp_lines_key']]);
        }

        unset($mappedPayload['id']);

        $normalizer = app(OdooFieldNormalizer::class);
        $write      = $normalizer->filterWritePayload('stock.picking', $mappedPayload);

        foreach ($write as $field => $value) {
            if ($value === null || $value === '') {
                unset($write[$field]);
            }
        }

        if ($write === []) {
            $this->recordCall('write', 'stock.picking', [
                'ids'    => [$pickingId],
                'values' => null,
                'note'   => 'skipped — no header fields to write after field-config + fields_get filter',
            ], null);

            return;
        }

        $result = $this->odoo->write('stock.picking', [$pickingId], $write);
        $this->recordCall('write', 'stock.picking', [
            'ids'    => [$pickingId],
            'values' => $write,
        ], $result);
    }

    /** @param  array<string, mixed>  $mappedPayload */
    private function applyMoveLinesFromMappedPayload(int $pickingId, array $mappedPayload): void
    {
        $linePayloads = $this->sync->extractDispatchLinePayloads($mappedPayload, 'ecom_to_erp');
        if ($linePayloads === []) {
            $this->recordCall('write', 'stock.move', [
                'ids'    => [],
                'values' => null,
                'note'   => 'skipped — no line ORM commands in mapped payload',
            ], null);

            return;
        }

        $container = $this->sync->resolveLineContainer('dispatch', 'ecom_to_erp');
        if ($container === null) {
            return;
        }

        $moveIds = $this->getMoveIdsForPicking($pickingId, $container['erp_lines_key']);
        if ($moveIds === []) {
            $this->recordCall('read', 'stock.picking', [
                'ids'    => [$pickingId],
                'fields' => [$container['erp_lines_key']],
                'note'   => 'no move ids on picking',
            ], []);

            return;
        }

        $normalizer = app(OdooFieldNormalizer::class);
        $moveFields = $normalizer->filterSearchReadFields(
            'stock.move',
            $this->sync->buildDispatchMoveReadFields('ecom_to_erp')
        );

        $moves = $this->odoo->read('stock.move', $moveIds, $moveFields);
        $this->recordCall('read', 'stock.move', [
            'ids'    => $moveIds,
            'fields' => $moveFields,
        ], $moves);

        $matched = false;

        foreach ($moves as $move) {
            foreach ($linePayloads as $linePayload) {
                if (!$this->sync->dispatchMoveMatchesLinePayload($move, $linePayload, 'ecom_to_erp')) {
                    continue;
                }

                $write = $normalizer->filterWritePayload('stock.move', $linePayload);
                foreach ($write as $field => $value) {
                    if ($value === null || $value === '') {
                        unset($write[$field]);
                    }
                }

                if ($write === []) {
                    $this->recordCall('write', 'stock.move', [
                        'ids'          => [(int) $move['id']],
                        'values'       => null,
                        'line_payload' => $linePayload,
                        'note'         => 'skipped — empty after fields_get filter',
                    ], null);
                    continue;
                }

                $result = $this->odoo->write('stock.move', [(int) $move['id']], $write);
                $this->recordCall('write', 'stock.move', [
                    'ids'          => [(int) $move['id']],
                    'values'       => $write,
                    'line_payload' => $linePayload,
                ], $result);
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            $this->recordCall('write', 'stock.move', [
                'ids'           => $moveIds,
                'values'        => null,
                'line_payloads' => $linePayloads,
                'note'          => 'skipped — no stock.move matched line payload (check product_id mapping + sync_mapping:product)',
            ], null);
        }
    }

    /** @return list<int> */
    private function getMoveIdsForPicking(int $pickingId, string $linesKey): array
    {
        $idField = str_ends_with($linesKey, '_ids')
            ? $linesKey
            : $this->sync->inferLineIdFieldName($linesKey);

        if ($idField === null || $idField === '') {
            return [];
        }

        $normalizer = app(OdooFieldNormalizer::class);
        $readFields = $normalizer->filterSearchReadFields('stock.picking', [$idField, 'id']);
        $rows       = $this->odoo->read('stock.picking', [$pickingId], $readFields);
        $this->recordCall('read', 'stock.picking', [
            'ids'    => [$pickingId],
            'fields' => $readFields,
        ], $rows);

        $ids = $rows[0][$idField] ?? [];

        return is_array($ids) ? array_map('intval', $ids) : [];
    }

    /** @param  array<string, mixed>  $picking */
    private function readPickingState(array $picking): ?string
    {
        $normalizer = app(OdooFieldNormalizer::class);
        $defs       = $normalizer->getFieldDefinitions('stock.picking');

        if (!isset($defs['state'])) {
            return null;
        }

        $state = $picking['state'] ?? null;

        return $state !== null && $state !== '' ? (string) $state : null;
    }

    private function validatePicking(int $pickingId): void
    {
        try {
            $result = $this->odoo->executeKw('stock.picking', 'button_validate', [[$pickingId]]);
            $this->recordCall('execute_kw', 'stock.picking', [
                'method' => 'button_validate',
                'args'   => [[$pickingId]],
            ], $result);
        } catch (\Throwable $e) {
            Log::warning("OdooDispatchService: button_validate failed for picking#{$pickingId}: " . $e->getMessage());

            try {
                $assign = $this->odoo->executeKw('stock.picking', 'action_assign', [[$pickingId]]);
                $this->recordCall('execute_kw', 'stock.picking', [
                    'method' => 'action_assign',
                    'args'   => [[$pickingId]],
                ], $assign);

                $result = $this->odoo->executeKw('stock.picking', 'button_validate', [[$pickingId]]);
                $this->recordCall('execute_kw', 'stock.picking', [
                    'method' => 'button_validate',
                    'args'   => [[$pickingId]],
                ], $result);
            } catch (\Throwable $retry) {
                $this->recordCall('execute_kw', 'stock.picking', [
                    'method' => 'button_validate',
                    'args'   => [[$pickingId]],
                ], ['error' => $retry->getMessage()]);
                $this->fail(
                    'Could not validate Odoo delivery #' . $pickingId . ': ' . $retry->getMessage(),
                    $retry
                );
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function getWireLog(): array
    {
        return $this->wireLog;
    }

    /** @param  array<string, mixed>  $request
     * @param  mixed  $result
     */
    private function recordCall(string $op, string $model, array $request, mixed $result): void
    {
        $this->wireLog[] = [
            'op'       => $op,
            'model'    => $model,
            'request'  => $request,
            'response' => $result,
        ];
    }

    private function fail(string $message, ?\Throwable $previous = null): never
    {
        throw new \RuntimeException($message, 0, $previous);
    }
}
