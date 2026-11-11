<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->decimal('sale_price', 12, 4)->nullable()->after('cost_price');
            $table->integer('min_stock')->nullable()->after('sale_price');
            $table->integer('expiry_alert_days')->nullable()->after('min_stock');
        });
    }

    public function down(): void
    {
        Schema::table('product_batches', function (Blueprint $table) {
            $table->dropColumn(['sale_price', 'min_stock', 'expiry_alert_days']);
        });
    }
};
