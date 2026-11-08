<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sale_unit', 50)->nullable()->after('min_stock');
            $table->string('purchase_unit', 50)->nullable()->after('sale_unit');
            $table->unsignedInteger('expiry_alert_days')->default(30)->after('purchase_unit');
            $table->unsignedInteger('low_stock_threshold')->default(0)->after('expiry_alert_days');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sale_unit', 'purchase_unit', 'expiry_alert_days', 'low_stock_threshold']);
        });
    }
};