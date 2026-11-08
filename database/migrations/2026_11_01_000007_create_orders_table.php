<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->string('order_number')->unique(); // ORD-20261101-0001
            $table->foreignId('table_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('cashier_id')->constrained('users')->onDelete('cascade'); // who created
            $table->foreignId('waiter_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('type', ['dine_in', 'takeaway', 'delivery'])->default('dine_in');
            $table->enum('status', ['pending', 'confirmed', 'preparing', 'ready', 'served', 'completed', 'cancelled'])->default('pending');
            $table->enum('payment_status', ['unpaid', 'partial', 'paid', 'refunded'])->default('unpaid');
            $table->string('payment_method')->nullable(); // cash, card, wallet
            $table->decimal('subtotal', 12, 4)->default(0);
            $table->decimal('discount_amount', 12, 4)->default(0);
            $table->decimal('discount_percent', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 4)->default(0);
            $table->decimal('total_amount', 12, 4)->default(0);
            $table->decimal('amount_paid', 12, 4)->default(0);
            $table->decimal('change_due', 12, 4)->default(0);
            $table->text('notes')->nullable();
            $table->text('customer_notes')->nullable();
            $table->string('delivery_address')->nullable();
            $table->string('delivery_phone')->nullable();
            $table->decimal('delivery_fee', 12, 4)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->onDelete('set null');
            $table->string('cancellation_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'status']);
            $table->index(['restaurant_id', 'type']);
            $table->index(['restaurant_id', 'payment_status']);
            $table->index(['table_id']);
            $table->index(['order_number']);
            $table->index(['created_at']);
            $table->index(['cashier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
