<?php

namespace App\Http\Controllers\Dashboard\Concerns;

use App\Services\Sync\SyncErrorFormatter;
use App\Services\Sync\UniversalSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait HandlesAjaxSyncResponses
{
    protected function syncErrorMessage(\Throwable $e): string
    {
        return SyncErrorFormatter::short($e) ?? 'Sync failed.';
    }

    protected function syncActionResponse(
        Request $request,
        string $level,
        string $message,
        array $data = [],
        int $status = 422,
        string $redirectRoute = 'dashboard.products',
    ): RedirectResponse|JsonResponse {
        if ($request->expectsJson()) {
            $httpStatus = $level === 'error' ? $status : 200;

            return response()->json(array_merge([
                'level'   => $level,
                'message' => $message,
            ], $data), $httpStatus);
        }

        return redirect()->route($redirectRoute)->with($level, $message);
    }

    protected function destroySyncEntity(
        Request $request,
        UniversalSyncService $syncService,
        string $entityType,
        string $localId,
        string $redirectRoute,
        string $idSide = 'auto',
        ?string $removedRowId = null,
    ): RedirectResponse|JsonResponse {
        try {
            $result = $syncService->deleteEntity($entityType, $localId, $idSide);

            return $this->syncActionResponse(
                $request,
                'success',
                $result['message'],
                array_filter([
                    'refresh_table'  => $removedRowId === null,
                    'removed_row_id' => $removedRowId,
                ]),
                redirectRoute: $redirectRoute,
            );
        } catch (\Throwable $e) {
            return $this->syncActionResponse(
                $request,
                'error',
                $this->syncErrorMessage($e),
                status: 422,
                redirectRoute: $redirectRoute,
            );
        }
    }

    protected function destroySyncEntitiesBulk(
        Request $request,
        UniversalSyncService $syncService,
        string $entityType,
        string $redirectRoute,
        callable $removedRowIdFor,
    ): RedirectResponse|JsonResponse {
        $ids = array_values(array_filter(array_map('strval', $request->input('ids', []))));

        if ($ids === []) {
            return $this->syncActionResponse(
                $request,
                'error',
                'No items selected.',
                status: 422,
                redirectRoute: $redirectRoute,
            );
        }

        $removedRowIds = [];
        $errors        = [];

        foreach ($ids as $id) {
            try {
                $syncService->deleteEntity($entityType, $id);
                $removedRowIds[] = $removedRowIdFor($id);
            } catch (\Throwable $e) {
                $errors[] = "{$id}: " . $this->syncErrorMessage($e);
            }
        }

        if ($removedRowIds === []) {
            return $this->syncActionResponse(
                $request,
                'error',
                $errors !== [] ? implode('; ', $errors) : 'Delete failed.',
                status: 422,
                redirectRoute: $redirectRoute,
            );
        }

        $message = count($removedRowIds) . ' item(s) deleted from e-commerce, ERP, and local database.';
        if ($errors !== []) {
            $message .= ' Warnings: ' . implode('; ', $errors);
        }

        return $this->syncActionResponse(
            $request,
            $errors === [] ? 'success' : 'warning',
            $message,
            ['removed_row_ids' => $removedRowIds],
            redirectRoute: $redirectRoute,
        );
    }
}
