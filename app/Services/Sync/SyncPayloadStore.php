<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\Storage;

/**
 * Fetched sync payloads live on disk only — keyed by entity type, source side, and id.
 * Nothing is stored in sync_mappings.metadata (that column stays null).
 *
 * Path layout: sync_payloads/{entity}/{erp|ecom}/{safe-id}.json
 */
class SyncPayloadStore
{
    public const DISK = 'local';
    public const BASE = 'sync_payloads';

    public static function sideForDirection(string $direction): string
    {
        return in_array($direction, ['ecom_to_erp', 'shopify_to_erp', 'shopify_to_odoo'], true)
            ? 'ecom'
            : 'erp';
    }

    public static function idFromKeys(string $side, array $keys): ?string
    {
        $column = $side === 'ecom' ? 'ecom_id' : 'erp_id';

        if (empty($keys[$column])) {
            return null;
        }

        return (string) $keys[$column];
    }

    public static function path(string $entityType, string $side, string $id): string
    {
        return self::BASE . '/' . $entityType . '/' . $side . '/' . self::sanitizeId($id) . '.json';
    }

    public static function put(string $entityType, string $side, string $id, array $payload): void
    {
        Storage::disk(self::DISK)->put(
            self::path($entityType, $side, $id),
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
    }

    public static function get(string $entityType, string $side, string $id): ?array
    {
        $path = self::path($entityType, $side, $id);

        if (!Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        $decoded = json_decode(Storage::disk(self::DISK)->get($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    public static function exists(string $entityType, string $side, string $id): bool
    {
        return Storage::disk(self::DISK)->exists(self::path($entityType, $side, $id));
    }

    public static function delete(string $entityType, string $side, string $id): void
    {
        $path = self::path($entityType, $side, $id);

        if (Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->delete($path);
        }
    }

    public static function sanitizeId(string $id): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', $id);

        return ($safe !== null && $safe !== '') ? $safe : md5($id);
    }
}
