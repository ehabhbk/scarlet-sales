<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('sub_unit', 50)->nullable()->after('purchase_unit');
            $table->unsignedInteger('conversion_factor')->default(1)->after('sub_unit');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sub_unit', 'conversion_factor']);
        });
    }
};