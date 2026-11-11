<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('unit_type', 10)->default('main')->after('quantity');
            $table->string('unit_name', 50)->nullable()->after('unit_type');
            $table->decimal('conversion_factor', 12, 4)->nullable()->after('unit_name');
            $table->decimal('base_quantity', 12, 4)->nullable()->after('conversion_factor');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['unit_type', 'unit_name', 'conversion_factor', 'base_quantity']);
        });
    }
};
