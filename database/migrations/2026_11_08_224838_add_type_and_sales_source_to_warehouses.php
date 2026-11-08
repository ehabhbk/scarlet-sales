<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Extend existing type enum to allow "both" (store + warehouse).
        DB::statement("ALTER TABLE warehouses MODIFY COLUMN type ENUM('store','pos','both') NOT NULL DEFAULT 'store'");

        Schema::table('warehouses', function (Blueprint $table) {
            $table->boolean('allow_sales')->default(true)->after('type');
            $table->enum('pos_sale_source', ['store', 'pos', 'both'])->nullable()->after('allow_sales');
        });
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropColumn(['allow_sales', 'pos_sale_source']);
        });
        DB::statement("ALTER TABLE warehouses MODIFY COLUMN type ENUM('store','pos') NOT NULL DEFAULT 'store'");
    }
};