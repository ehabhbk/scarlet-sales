<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->date('birth_date')->nullable();
            $table->string('customer_type', 20)->default('regular'); // regular, vip, corporate
            $table->integer('loyalty_points')->default(0);
            $table->decimal('total_spent', 12, 4)->default(0);
            $table->integer('total_orders')->default(0);
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'phone']);
            $table->index(['customer_type']);
            $table->index(['is_active']);
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('employee_id')->unique(); // EMP-001
            $table->string('name');
            $table->string('job_title'); // Cashier, Waiter, Chef, Accountant, Manager
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->date('hire_date');
            $table->decimal('salary', 12, 4)->default(0);
            $table->enum('status', ['active', 'on_leave', 'terminated'])->default('active');
            $table->string('avatar')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'job_title']);
            $table->index(['user_id']);
            $table->index(['employee_id']);
            $table->index(['status']);
        });

        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->onDelete('cascade');
            $table->date('work_date');
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->decimal('work_hours', 5, 2)->default(0);
            $table->enum('status', ['present', 'absent', 'late', 'half_day'])->default('present');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['employee_id', 'work_date']);
            $table->unique(['employee_id', 'work_date']);
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade'); // cashier on shift
            $table->foreignId('employee_id')->nullable()->constrained()->onDelete('set null');
            $table->string('name'); // Morning Shift, Evening Shift
            $table->date('shift_date');
            $table->time('start_time');
            $table->time('end_time');
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->decimal('opening_cash', 12, 4)->default(0);
            $table->decimal('expected_cash', 12, 4)->default(0);
            $table->decimal('actual_cash', 12, 4)->default(0);
            $table->decimal('difference', 12, 4)->default(0);
            $table->timestamp('closed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'shift_date']);
            $table->index(['user_id']);
            $table->index(['status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('attendances');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('customers');
    }
};
