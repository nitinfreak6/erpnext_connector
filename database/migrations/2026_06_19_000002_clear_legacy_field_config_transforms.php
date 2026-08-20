<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function isSystemTransform(?string $transform): bool
    {
        $transform = trim($transform ?? '');
        if ($transform === '') {
            return true;
        }

        if (in_array($transform, [
            'line_container',
            'skip',
            'synced_customer',
            'image_url_to_base64',
            'resolve_product_by_sku',
        ], true)) {
            return true;
        }

        return str_starts_with($transform, 'channel_map:')
            || str_starts_with($transform, 'resolve_partner:');
    }

    public function up(): void
    {
        // weight_unit → conditions
        DB::table('product_field_configs')
            ->where('transform', 'weight_unit')
            ->where(function ($q) {
                $q->whereNull('conditions')->orWhere('conditions', '');
            })
            ->update([
                'conditions' => 'kg:KILOGRAMS, g:GRAMS, lb:POUNDS, oz:OUNCES, kilograms:KILOGRAMS, grams:GRAMS, pounds:POUNDS, ounces:OUNCES',
                'transform'  => null,
            ]);

        DB::table('product_field_configs')
            ->where('transform', 'shopify_weight_unit')
            ->where(function ($q) {
                $q->whereNull('conditions')->orWhere('conditions', '');
            })
            ->update([
                'conditions' => 'kg:KILOGRAMS, g:GRAMS, lb:POUNDS, oz:OUNCES',
                'transform'  => null,
            ]);

        // Uppercase enums → conditions where common
        DB::table('product_field_configs')
            ->whereIn('transform', ['uppercase', 'shopify_inventory_policy'])
            ->update(['transform' => null]);

        $rows = DB::table('product_field_configs')
            ->select('id', 'transform', 'reverse_transform', 'conditions')
            ->get();

        foreach ($rows as $row) {
            $updates = [];

            if (!$this->isSystemTransform($row->transform)) {
                $updates['transform'] = null;
            }

            if (!$this->isSystemTransform($row->reverse_transform)) {
                $updates['reverse_transform'] = null;
            }

            if ($updates !== []) {
                DB::table('product_field_configs')->where('id', $row->id)->update($updates);
            }
        }
    }

    public function down(): void
    {
        // Legacy transforms are not restored.
    }
};
