<?php

namespace App\Support;

/** Normalize ERP record IDs — Odoo uses integers, ERPNext uses string doc names. */
final class ErpId
{
    public static function normalize(mixed $id): int|string
    {
        if ($id === null || $id === '' || $id === false) {
            return 0;
        }

        if (is_int($id)) {
            return $id;
        }

        $str = trim((string) $id);

        if ($str === '' || $str === '0') {
            return 0;
        }

        return ctype_digit($str) ? (int) $str : $str;
    }

    public static function normalizeOrFail(mixed $id): int|string
    {
        $normalized = self::normalize($id);

        if ($normalized === 0 || $normalized === '0') {
            throw new \InvalidArgumentException('Invalid ERP ID: ' . var_export($id, true));
        }

        return $normalized;
    }
}
