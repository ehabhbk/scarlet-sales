<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_employee_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->string('payment_method', 50)->nullable();
            $table->timestamps();
            $table->unique(['shift_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shift_employee_payments');
    }
};
