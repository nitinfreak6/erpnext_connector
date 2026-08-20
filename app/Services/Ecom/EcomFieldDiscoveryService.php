<?php

namespace App\Services\Ecom;

/**
 * Flatten fetched e-commerce product JSON into mappable field paths for
 * ecom→ERP field config (dot notation, array indexes, metafield shortcuts).
 */
class EcomFieldDiscoveryService
{
    private const MAX_DEPTH = 10;

    /**
     * @param  array<int, array<string, mixed>>  $products
     * @return array{template_fields: array<int, array<string, mixed>>, variant_fields: array<int, array<string, mixed>>, fields: array<int, mixed>}
     */
    public function discoverFromProducts(array $products): array
    {
        $templateMap = [];
        $variantMap  = [];

        foreach ($products as $product) {
            if (!is_array($product) || empty($product)) {
                continue;
            }

            $this->collectFields($product, '', 'template', $templateMap, ['variants']);
            $this->appendMetafieldShortcuts($product, $templateMap);

            foreach ($product['variants'] ?? [] as $variant) {
                if (!is_array($variant)) {
                    continue;
                }
                $this->collectFields($variant, '', 'variant', $variantMap);
                break;
            }
        }

        return [
            'template_fields' => $this->toFieldList($templateMap),
            'variant_fields'  => $this->toFieldList($variantMap),
            'fields'          => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, array<string, mixed>>  $map
     * @param  list<string>  $excludeKeys
     */
    private function collectFields(
        array $data,
        string $prefix,
        string $scope,
        array &$map,
        array $excludeKeys = [],
        int $depth = 0
    ): void {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        foreach ($data as $key => $value) {
            if ($prefix === '' && in_array($key, $excludeKeys, true)) {
                continue;
            }

            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if ($this->isScalar($value)) {
                $this->rememberField($map, $path, $scope, $value);
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            if ($value === []) {
                $this->rememberField($map, $path, $scope, '[]');
                continue;
            }

            if ($this->isListArray($value)) {
                if ($this->allScalars($value)) {
                    $this->rememberField($map, $path, $scope, $value);
                    foreach ($value as $i => $item) {
                        $this->rememberField($map, "{$path}.{$i}", $scope, $item);
                    }
                    continue;
                }

                foreach ($value as $i => $item) {
                    if (is_array($item)) {
                        $this->collectFields($item, "{$path}.{$i}", $scope, $map, [], $depth + 1);
                    } else {
                        $this->rememberField($map, "{$path}.{$i}", $scope, $item);
                    }
                }
                continue;
            }

            $this->collectFields($value, $path, $scope, $map, [], $depth + 1);
        }
    }

    /**
     * @param  array<string, mixed>  $product
     * @param  array<string, array<string, mixed>>  $map
     */
    private function appendMetafieldShortcuts(array $product, array &$map): void
    {
        foreach ($product['metafields'] ?? [] as $metafield) {
            if (!is_array($metafield)) {
                continue;
            }

            $namespace = trim((string) ($metafield['namespace'] ?? ''));
            $key       = trim((string) ($metafield['key'] ?? ''));
            if ($namespace === '' || $key === '') {
                continue;
            }

            $path = "{$namespace}.{$key}";
            $this->rememberField(
                $map,
                $path,
                'template',
                $metafield['value'] ?? null,
                "Metafield {$namespace}.{$key}"
            );
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $map
     */
    private function rememberField(
        array &$map,
        string $path,
        string $scope,
        mixed $sample,
        ?string $label = null
    ): void {
        if (isset($map[$path])) {
            return;
        }

        $map[$path] = [
            'key'    => $path,
            'label'  => $label ?? $this->labelFor($path),
            'scope'  => $scope,
            'sample' => $this->formatSample($sample),
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $map
     * @return array<int, array<string, mixed>>
     */
    private function toFieldList(array $map): array
    {
        $fields = array_values($map);
        usort($fields, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return $fields;
    }

    private function labelFor(string $path): string
    {
        return str_replace(['.', '_'], [' › ', ' '], $path);
    }

    private function formatSample(mixed $sample): ?string
    {
        if ($sample === null) {
            return null;
        }

        if (is_bool($sample)) {
            return $sample ? 'true' : 'false';
        }

        if (is_scalar($sample)) {
            $text = (string) $sample;
            return strlen($text) > 80 ? substr($text, 0, 77) . '...' : $text;
        }

        if (is_array($sample)) {
            $encoded = json_encode($sample, JSON_UNESCAPED_UNICODE);
            if ($encoded === false) {
                return null;
            }
            return strlen($encoded) > 80 ? substr($encoded, 0, 77) . '...' : $encoded;
        }

        return null;
    }

    private function isScalar(mixed $value): bool
    {
        return $value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value);
    }

    /** @param array<mixed> $value */
    private function isListArray(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /** @param array<mixed> $value */
    private function allScalars(array $value): bool
    {
        foreach ($value as $item) {
            if (!$this->isScalar($item)) {
                return false;
            }
        }

        return true;
    }
}
