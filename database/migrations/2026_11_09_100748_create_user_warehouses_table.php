<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_warehouses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('warehouse_id');
            $table->boolean('can_sell')->default(false);
            $table->boolean('can_request_stock')->default(false);
            $table->boolean('can_approve_stock')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->unique(['user_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_warehouses');
    }
};