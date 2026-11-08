<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Kilogram, Piece, Liter
            $table->string('abbreviation'); // kg, pcs, L
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'is_active']);
        });

        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('tax_number')->nullable();
            $table->decimal('balance', 12, 4)->default(0); // amount owed to supplier
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'is_active']);
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('unit_id')->constrained('inventory_units')->onDelete('restrict');
            $table->foreignId('supplier_id')->nullable()->constrained()->onDelete('set null');
            $table->string('name');
            $table->string('sku')->nullable()->unique();
            $table->string('category')->nullable(); // dairy, meat, vegetables, beverages
            $table->decimal('current_stock', 12, 4)->default(0);
            $table->decimal('min_stock', 12, 4)->default(0);
            $table->decimal('reorder_qty', 12, 4)->default(0);
            $table->decimal('cost_per_unit', 12, 4)->default(0);
            $table->date('expiry_date')->nullable();
            $table->string('batch_number')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'unit_id']);
            $table->index(['supplier_id']);
            $table->index(['sku']);
            $table->index(['category']);
            $table->index(['expiry_date']);
        });

        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('supplier_id')->constrained()->onDelete('restrict');
            $table->foreignId('user_id')->constrained('users')->onDelete('restrict');
            $table->string('purchase_number')->unique();
            $table->enum('status', ['draft', 'ordered', 'received', 'cancelled'])->default('draft');
            $table->decimal('subtotal', 12, 4)->default(0);
            $table->decimal('tax_amount', 12, 4)->default(0);
            $table->decimal('total_amount', 12, 4)->default(0);
            $table->decimal('amount_paid', 12, 4)->default(0);
            $table->enum('payment_status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->date('order_date');
            $table->date('expected_date')->nullable();
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'supplier_id']);
            $table->index(['status']);
            $table->index(['purchase_number']);
            $table->index(['order_date']);
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->onDelete('cascade');
            $table->foreignId('inventory_item_id')->constrained()->onDelete('restrict');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_cost', 12, 4)->default(0);
            $table->decimal('total_cost', 12, 4)->default(0);
            $table->date('expiry_date')->nullable();
            $table->string('batch_number')->nullable();
            $table->timestamps();

            $table->index(['purchase_id']);
            $table->index(['inventory_item_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('inventory_item_id')->constrained()->onDelete('restrict');
            $table->enum('type', ['in', 'out', 'adjustment'])->default('in');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('cost_per_unit', 12, 4)->default(0);
            $table->decimal('total_cost', 12, 4)->default(0);
            $table->string('reference_type')->nullable(); // purchase, recipe, order, adjustment
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'inventory_item_id']);
            $table->index(['type']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('suppliers');
        Schema::dropIfExists('inventory_units');
    }
};
