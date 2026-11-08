<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restaurant_id');
            $table->unsignedBigInteger('from_warehouse_id');
            $table->unsignedBigInteger('to_warehouse_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('requested_quantity', 12, 3);
            $table->decimal('approved_quantity', 12, 3)->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected', 'completed'])->default('pending');
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->string('rejected_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('restaurant_id')->references('id')->on('restaurants')->cascadeOnDelete();
            $table->foreign('from_warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('to_warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_requests');
    }
};