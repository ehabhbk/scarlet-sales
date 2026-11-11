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
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->decimal('main_quantity', 12, 4)->nullable()->after('quantity');
            $table->decimal('sub_quantity_per_main', 12, 4)->nullable()->after('main_quantity');
            $table->string('unit_name')->nullable()->after('sub_quantity_per_main');
            $table->string('sub_unit_name')->nullable()->after('unit_name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchase_items', function (Blueprint $table) {
            $table->dropColumn(['main_quantity', 'sub_quantity_per_main', 'unit_name', 'sub_unit_name']);
        });
    }
};
