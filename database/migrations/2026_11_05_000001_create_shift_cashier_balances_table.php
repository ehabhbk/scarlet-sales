<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_cashier_balances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('opening_cash', 12, 4)->default(0);
            $table->boolean('opening_cash_set')->default(false);
            $table->decimal('expected_cash', 12, 4)->default(0);
            $table->decimal('actual_cash', 12, 4)->nullable();
            $table->decimal('difference', 12, 4)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['shift_id', 'user_id']);
            $table->index(['restaurant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_cashier_balances');
    }
};
