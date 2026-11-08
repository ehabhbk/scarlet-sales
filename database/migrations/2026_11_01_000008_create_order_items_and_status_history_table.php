<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('size_id')->nullable()->constrained('product_sizes')->onDelete('set null');
            $table->integer('quantity')->default(1);
            $table->decimal('unit_price', 12, 4)->default(0);
            $table->decimal('discount_amount', 12, 4)->default(0);
            $table->decimal('tax_amount', 12, 4)->default(0);
            $table->decimal('total_price', 12, 4)->default(0);
            $table->text('notes')->nullable(); // kitchen notes
            $table->enum('kitchen_status', ['pending', 'confirmed', 'preparing', 'ready', 'served', 'cancelled'])->default('pending');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['order_id']);
            $table->index(['product_id']);
            $table->index(['kitchen_status']);
        });

        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('modifier_id')->constrained('product_modifiers')->onDelete('cascade');
            $table->foreignId('modifier_option_id')->constrained('modifier_options')->onDelete('cascade');
            $table->decimal('price_modifier', 12, 4)->default(0);
            $table->timestamps();

            $table->index(['order_item_id']);
            $table->unique(['order_item_id', 'modifier_id', 'modifier_option_id'])->name('uix_order_item_modifier');
        });

        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->string('old_status')->nullable();
            $table->string('new_status');
            $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['order_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
    }
};
