<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $pairs = [
            'first_name' => 'firstName',
            'last_name'  => 'lastName',
        ];

        foreach ($pairs as $from => $to) {
            DB::table('product_field_configs')
                ->where('entity_type', 'customer')
                ->where('ecom_field', $from)
                ->update(['ecom_field' => $to]);
        }
    }

    public function down(): void
    {
        $pairs = [
            'firstName' => 'first_name',
            'lastName'  => 'last_name',
        ];

        foreach ($pairs as $from => $to) {
            DB::table('product_field_configs')
                ->where('entity_type', 'customer')
                ->where('ecom_field', $from)
                ->update(['ecom_field' => $to]);
        }
    }
};
