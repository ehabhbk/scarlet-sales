<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained()->onDelete('set null');
            $table->string('name');
            $table->string('slug')->nullable();
            $table->string('sku')->nullable()->unique();
            $table->string('barcode')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->decimal('sale_price', 12, 4)->default(0);
            $table->decimal('cost_price', 12, 4)->default(0);
            $table->decimal('tax_rate', 5, 4)->nullable(); // null = use restaurant default
            $table->boolean('track_inventory')->default(true);
            $table->integer('current_stock')->default(0);
            $table->integer('min_stock')->default(0);
            $table->boolean('is_available')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_best_seller')->default(false);
            $table->integer('sort_order')->default(0);
            $table->integer('sales_count')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'category_id']);
            $table->index(['is_available']);
            $table->index(['sku']);
            $table->index(['barcode']);
            $table->index(['is_best_seller']);
            $table->index(['slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
