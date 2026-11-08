<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('category'); // rent, utilities, salary, marketing, maintenance, other
            $table->decimal('amount', 12, 4)->default(0);
            $table->date('expense_date');
            $table->text('description')->nullable();
            $table->string('receipt')->nullable(); // file path
            $table->string('payment_method')->nullable(); // cash, card, bank
            $table->boolean('is_approved')->default(false);
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'category']);
            $table->index(['expense_date']);
            $table->index(['user_id']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->morphs('paymentable'); // order, purchase, expense, etc.
            $table->decimal('amount', 12, 4)->default(0);
            $table->enum('method', ['cash', 'card', 'wallet', 'bank_transfer', 'cheque']);
            $table->string('reference')->nullable();
            $table->string('transaction_id')->nullable();
            $table->timestamp('paid_at');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id']);
            $table->index(['method']);
            $table->index(['paid_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->string('action'); // created, updated, deleted, restored, login, logout, order_completed
            $table->string('subject_type')->nullable(); // model class
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->longText('old_values')->nullable();
            $table->longText('new_values')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamps();

            $table->index(['user_id']);
            $table->index(['subject_type', 'subject_id']);
            $table->index(['action']);
            $table->index(['created_at']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->string('group'); // general, invoice, printing, delivery, notification
            $table->string('key');
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string, number, boolean, json
            $table->timestamps();

            $table->unique(['restaurant_id', 'group', 'key']);
            $table->index(['restaurant_id', 'group']);
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('message');
            $table->string('type')->default('info'); // info, warning, error, success, stock_low
            $table->string('link')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'user_id']);
            $table->index(['is_read']);
            $table->index(['type']);
            $table->index(['created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('settings');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('expenses');
    }
};
