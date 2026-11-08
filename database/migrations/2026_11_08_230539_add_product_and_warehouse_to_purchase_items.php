<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable()->after('purchase_id');
            $table->unsignedBigInteger('warehouse_id')->nullable()->after('product_id');
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
        });

        DB::statement('ALTER TABLE purchase_items MODIFY inventory_item_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn(['product_id', 'warehouse_id', 'batch_number']);
        });
    }
};