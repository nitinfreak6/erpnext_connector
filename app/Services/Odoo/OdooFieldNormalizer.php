<?php

namespace App\Services\Odoo;

use App\Models\ChannelMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Generic Odoo field read/write normalization — no per-field code changes.
 *
 * Odoo READ  returns many2one as [id, "Label"]  e.g. [20, "INR"]
 * Odoo WRITE expects many2one as integer ID only e.g. 20
 *
 * Field config drives which fields sync; this class handles format conversion
 * for any many2one on any model using fields_get metadata from Odoo.
 */
class OdooFieldNormalizer
{
    /** one2many fields that need special ORM command builders (field => callable). */
    private array $one2manyHandlers = [];

    public function __construct(private readonly OdooService $odoo) {}

    /**
     * Register a handler for a one2many field (e.g. seller_ids).
     *
     * @param callable(mixed $value, ?int $recordId): mixed $handler
     */
    public function registerOne2ManyHandler(string $field, callable $handler): self
    {
        $this->one2manyHandlers[$field] = $handler;

        return $this;
    }

    /**
     * Extract integer ID for Odoo many2one write from int|string|[id, label].
     */
    public function extractMany2OneId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_array($value)) {
            $id = $value[0] ?? null;

            return is_numeric($id) ? (int) $id : null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * Normalize a payload before Odoo create/write using live field metadata.
     *
     * @param  array<string, callable(mixed, ?int): mixed>  $one2manyHandlers
     */
    public function normalizeWritePayload(
        string $model,
        array $vals,
        ?int $recordId = null,
        array $one2manyHandlers = [],
    ): array {
        $handlers = array_merge($this->one2manyHandlers, $one2manyHandlers);
        $fields   = $this->getFieldDefinitions($model);

        foreach (array_keys($vals) as $key) {
            $def = $fields[$key] ?? null;

            if ($def === null) {
                // Fallback: fields ending in _id are treated as many2one
                if ($this->looksLikeMany2OneFieldName($key)) {
                    $vals[$key] = $this->normalizeMany2OneValue($key, $vals[$key], null);
                    if ($vals[$key] === null) {
                        unset($vals[$key]);
                    }
                }
                continue;
            }

            $type = $def['type'] ?? '';

            if ($type === 'many2one') {
                $relation = $def['relation'] ?? null;
                $vals[$key] = $this->normalizeMany2OneValue($key, $vals[$key], $relation);
                if ($vals[$key] === null) {
                    unset($vals[$key]);
                }
                continue;
            }

            if ($type === 'selection') {
                $vals[$key] = $this->normalizeSelectionValue($key, $vals[$key], $def);
                if ($vals[$key] === null) {
                    unset($vals[$key]);
                }
                continue;
            }

            if ($type === 'many2many' || ($type === 'one2many' && !isset($handlers[$key]))) {
                if (!$this->isValidOdooRelationCommands($vals[$key])) {
                    Log::warning("OdooFieldNormalizer: skipping {$key} — many2many/one2many requires Odoo ORM commands, not plain strings/arrays", [
                        'value' => $vals[$key],
                        'hint'  => 'Map tags to website_meta_keywords (text), not product_tag_ids.',
                    ]);
                    unset($vals[$key]);
                }
                continue;
            }

            if ($type === 'one2many' && isset($handlers[$key])) {
                $vals[$key] = $handlers[$key]($vals[$key], $recordId);
                if ($vals[$key] === null) {
                    unset($vals[$key]);
                }
            }
        }

        return $vals;
    }

    /**
     * Format integer many2one IDs as [id, "label"] for display (info page).
     */
    public function formatMany2OneForDisplay(string $model, array $values): array
    {
        $fields = $this->getFieldDefinitions($model);

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                continue; // already read-format
            }

            if (!is_numeric($value)) {
                continue;
            }

            $def = $fields[$key] ?? null;
            $isMany2One = ($def['type'] ?? '') === 'many2one'
                || ($def === null && $this->looksLikeMany2OneFieldName($key));

            if (!$isMany2One) {
                continue;
            }

            $id    = (int) $value;
            $label = $this->resolveMany2OneLabel($id, $def['relation'] ?? null);
            $values[$key] = [$id, $label];
        }

        return $values;
    }

    /**
     * Cached fields_get for any Odoo model.
     */
    public function getFieldDefinitions(string $model): array
    {
        $cacheKey = 'odoo_fields_get:' . $model;

        return Cache::remember($cacheKey, 3600, function () use ($model) {
            try {
                return $this->odoo->executeKw(
                    $model,
                    'fields_get',
                    [],
                    ['attributes' => ['type', 'relation', 'string', 'selection']]
                ) ?: [];
            } catch (\Throwable $e) {
                Log::warning("OdooFieldNormalizer: fields_get failed for {$model}: " . $e->getMessage());

                return [];
            }
        });
    }

    /**
     * All res.partner (or any model) field names suitable for search_read —
     * discovered from Odoo fields_get, not hardcoded or field-config driven.
     * Skips only huge binary image blobs that would bloat stored JSON.
     *
     * @return list<string>
     */
    public function getSearchReadFieldNames(string $model): array
    {
        $fields = $this->getFieldDefinitions($model);

        if ($fields === []) {
            return [];
        }

        $names = [];

        foreach (array_keys($fields) as $name) {
            $type = $fields[$name]['type'] ?? '';

            if ($type === 'binary' && (str_starts_with($name, 'image_') || $name === 'avatar_128')) {
                continue;
            }

            $names[] = $name;
        }

        sort($names);

        return $names;
    }

    private function normalizeMany2OneValue(string $field, mixed $value, ?string $relation): ?int
    {
        $id = $this->extractMany2OneId($value);

        if ($id === null || $id <= 0) {
            return null;
        }

        if ($relation && !$this->recordExists($relation, $id)) {
            Log::warning("OdooFieldNormalizer: skipping {$field} — {$relation}#{$id} not found in Odoo", [
                'hint' => 'Check field config default_value or Channel Mapping.',
            ]);

            return null;
        }

        return $id;
    }

    private function recordExists(string $model, int $id): bool
    {
        try {
            $rows = $this->odoo->read($model, [$id], ['id']);

            return !empty($rows);
        } catch (\Throwable) {
            return false;
        }
    }

    private function looksLikeMany2OneFieldName(string $field): bool
    {
        return str_ends_with($field, '_id') && !str_ends_with($field, '_ids');
    }

    /**
     * Drop keys Odoo does not accept on create/write for this model (version-safe).
     */
    public function filterWritePayload(string $model, array $vals): array
    {
        $fields = $this->getFieldDefinitions($model);

        if ($fields === []) {
            return [];
        }

        return array_intersect_key($vals, $fields);
    }

    /**
     * Keep only search_read field names that exist on this Odoo model.
     *
     * @param  list<string>  $requested
     * @return list<string>
     */
    public function filterSearchReadFields(string $model, array $requested): array
    {
        $fields = $this->getFieldDefinitions($model);

        if ($fields === []) {
            return in_array('id', $requested, true) ? ['id'] : [];
        }

        $filtered = array_values(array_filter(
            $requested,
            static fn (string $name) => isset($fields[$name])
        ));

        if ($filtered === []) {
            return isset($fields['id']) ? ['id'] : [];
        }

        if (!in_array('id', $filtered, true) && isset($fields['id'])) {
            array_unshift($filtered, 'id');
        }

        return $filtered;
    }

    /**
     * Fail fast when field config maps to ERP fields that do not exist on the model.
     *
     * @throws \InvalidArgumentException
     */
    public function assertKnownWriteFields(string $model, array $vals): void
    {
        $fields = $this->getFieldDefinitions($model);

        if ($fields === []) {
            return;
        }

        $unknown = array_values(array_diff(array_keys($vals), array_keys($fields)));

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Invalid ERP field(s): ' . implode(', ', $unknown)
                . ' — check Field Config ERP column.'
            );
        }
    }

    /**
     * Odoo selection fields accept keys only (consu, service, combo) — not UI labels (Goods, Service…).
     */
    private function normalizeSelectionValue(string $field, mixed $value, array $def): ?string
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        $str       = trim((string) $value);
        $keys      = $this->extractSelectionKeys($def['selection'] ?? []);
        $labelToKey = $this->extractSelectionLabelMap($def['selection'] ?? []);

        if ($keys !== [] && in_array($str, $keys, true)) {
            return $str;
        }

        // Label from Odoo UI or field-config dropdown, e.g. "Goods" → consu
        if (isset($labelToKey[$str])) {
            return $labelToKey[$str];
        }

        $lower = strtolower($str);
        if (isset($labelToKey[$lower])) {
            return $labelToKey[$lower];
        }

        // Odoo 19 product.template.type common labels
        $fallback = match ($lower) {
            'goods', 'consumable', 'consumables', 'product', 'storable' => 'consu',
            'service', 'services' => 'service',
            'combo', 'combination' => 'combo',
            default => null,
        };

        if ($fallback !== null && ($keys === [] || in_array($fallback, $keys, true))) {
            Log::info("OdooFieldNormalizer: mapped selection label '{$str}' → '{$fallback}' for {$field}");
            return $fallback;
        }

        Log::warning("OdooFieldNormalizer: skipping invalid selection value for {$field}", [
            'value'   => $str,
            'allowed' => $keys,
            'hint'    => 'Use field config default consu/service/combo — not the Odoo UI label.',
        ]);

        return null;
    }

    /** @return list<string> */
    private function extractSelectionKeys(mixed $selection): array
    {
        if (!is_array($selection)) {
            return [];
        }

        $keys = [];
        foreach ($selection as $item) {
            if (is_array($item) && isset($item[0])) {
                $keys[] = (string) $item[0];
            } elseif (is_string($item)) {
                $keys[] = $item;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Odoo x2many writes require command tuples like [[6, 0, [ids]]] — not tag names.
     */
    private function isValidOdooRelationCommands(mixed $value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }

        foreach ($value as $command) {
            if (!is_array($command) || !array_key_exists(0, $command)) {
                return false;
            }

            $opcode = $command[0];
            if (!is_int($opcode) && !(is_string($opcode) && ctype_digit($opcode))) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, string> label (lowered) → key */
    private function extractSelectionLabelMap(mixed $selection): array
    {
        if (!is_array($selection)) {
            return [];
        }

        $map = [];
        foreach ($selection as $item) {
            if (!is_array($item) || !isset($item[0], $item[1])) {
                continue;
            }
            $key   = (string) $item[0];
            $label = (string) $item[1];
            $map[$label] = $key;
            $map[strtolower($label)] = $key;
        }

        return $map;
    }

    private function resolveMany2OneLabel(int $id, ?string $relation): string
    {
        $mapType = match ($relation) {
            'product.category' => ChannelMapping::TYPE_CATEGORY,
            default            => null,
        };

        if ($mapType !== null) {
            $fromMap = ChannelMapping::query()
                ->ofType($mapType)
                ->where('odoo_id', (string) $id)
                ->value('odoo_label');

            if ($fromMap) {
                return $fromMap;
            }
        }

        if (!$relation) {
            return '';
        }

        try {
            $rows = $this->odoo->read($relation, [$id], ['display_name', 'name', 'complete_name']);
            $row  = $rows[0] ?? [];

            return $row['complete_name'] ?? $row['display_name'] ?? $row['name'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }
}
