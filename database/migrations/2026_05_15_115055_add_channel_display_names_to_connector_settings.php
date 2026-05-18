<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
	{
		// Only insert if they don't already exist
		$rows = [
			[
				'key'          => 'shopify_display_name',
				'label'        => 'Shopify Display Name',
				'description'  => 'Replaces "Shopify" wherever channel labels appear in the UI.',
				'value'        => null,
				'default_value'=> 'Shopify',
				'field_type'   => 'text',
				'group'        => 'general',
				'sort_order'   => 12,
				'is_secret'    => false,
				'is_active'    => true,
			],
			[
				'key'          => 'amazon_display_name',
				'label'        => 'Amazon Display Name',
				'description'  => 'Replaces "Amazon" wherever channel labels appear in the UI.',
				'value'        => null,
				'default_value'=> 'Amazon',
				'field_type'   => 'text',
				'group'        => 'general',
				'sort_order'   => 13,
				'is_secret'    => false,
				'is_active'    => true,
			],
		];

		foreach ($rows as $row) {
			\DB::table('connector_settings')
				->insertOrIgnore($row);
		}
	}

	public function down(): void
	{
		\DB::table('connector_settings')
			->whereIn('key', ['shopify_display_name', 'amazon_display_name'])
			->delete();
	}
};
