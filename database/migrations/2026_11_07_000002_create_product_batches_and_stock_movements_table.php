<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('supplier_id')->nullable()->constrained()->onDelete('set null');
            $table->string('batch_number', 100)->nullable();
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('cost_price', 12, 4)->default(0);
            $table->date('expiry_date')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'warehouse_id', 'product_id'], 'batches_rest_wh_prod_idx');
            $table->index(['restaurant_id', 'expiry_date']);
            $table->index(['product_id', 'expiry_date']);
        });

        Schema::create('product_stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('warehouse_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_batch_id')->nullable()->constrained('product_batches')->onDelete('set null');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('type', ['in', 'out', 'transfer_in', 'transfer_out', 'adjustment']);
            $table->decimal('quantity', 14, 3)->default(0);
            $table->decimal('cost_price', 12, 4)->default(0);
            $table->string('reference_type', 50)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'warehouse_id', 'product_id'], 'movements_rest_wh_prod_idx');
            $table->index(['reference_type', 'reference_id']);
            $table->index(['type']);
            $table->index(['created_at']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('warehouse_id')->nullable()->after('shift_id')
                ->constrained('warehouses')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('warehouse_id');
        });

        Schema::dropIfExists('product_stock_movements');
        Schema::dropIfExists('product_batches');
    }
};
