<?php

namespace App\Services\Config;

/**
 * Config-driven value maps: "1:ACTIVE, 0:DRAFT" or "true:1, false:0".
 *
 * Format: sourceValue:targetValue pairs separated by comma or newline.
 * Keys may contain spaces (e.g. "Kilogram/Cubic Centimeter:KILOGRAMS").
 * Direction is defined by the field-config row:
 *   erp_to_ecom → Odoo/source : Shopify/target
 *   ecom_to_erp → Shopify/source : Odoo/target
 */
class ValueConditionMapper
{
    /**
     * @return array<string, string> normalized source key => target value
     */
    public function parse(string $conditions): array
    {
        $map = [];

        foreach (preg_split('/\s*,\s*|\R/', trim($conditions), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pair) {
            if (!str_contains($pair, ':')) {
                continue;
            }

            [$source, $target] = array_map('trim', explode(':', $pair, 2));
            if ($source === '' || $target === '') {
                continue;
            }

            $map[$this->normalizeKey($source)] = $target;
        }

        return $map;
    }

    public function apply(mixed $input, ?string $conditions, mixed $fallback = null): mixed
    {
        if ($conditions === null || trim($conditions) === '') {
            return $input;
        }

        $map = $this->parse($conditions);
        if ($map === []) {
            return $input;
        }

        foreach ($this->candidateKeys($input) as $key) {
            if (array_key_exists($key, $map)) {
                return $this->castOutput($map[$key]);
            }
        }

        if ($fallback !== null && $fallback !== '' && $fallback !== '__NULL__') {
            return $this->castOutput((string) $fallback);
        }

        return $input;
    }

    /** @return list<string> */
    private function candidateKeys(mixed $input): array
    {
        $keys = [];

        if (is_array($input) && array_key_exists(0, $input)) {
            foreach ([$input[1] ?? null, $input[0] ?? null] as $part) {
                foreach ($this->candidateKeys($part) as $key) {
                    $keys[] = $key;
                }
            }

            return array_values(array_unique($keys));
        }

        if (is_bool($input)) {
            $keys[] = $input ? '1' : '0';
            $keys[] = $input ? 'true' : 'false';
        } elseif (is_int($input) || is_float($input)) {
            $keys[] = (string) (int) $input;
            $keys[] = (string) $input;
        }

        $raw = strtolower(trim((string) $input));
        if ($raw !== '') {
            $keys[] = $this->normalizeKey($raw);
            $keys[] = $raw;
        }

        return array_values(array_unique($keys));
    }

    private function normalizeKey(string $key): string
    {
        $key = strtolower(trim($key));

        return match ($key) {
            'true', 'yes', 'y' => '1',
            'false', 'no', 'n' => '0',
            default => $key,
        };
    }

    private function castOutput(string $value): mixed
    {
        $lower = strtolower($value);

        return match ($lower) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            default => is_numeric($value) && !str_contains($value, '.')
                ? (int) $value
                : $value,
        };
    }
}
