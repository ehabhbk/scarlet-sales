<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('pay_type', 20)->default('monthly')->after('salary'); // monthly, daily, percentage
            $table->decimal('pay_rate', 15, 2)->default(0)->after('pay_type');
            $table->foreignId('pay_account_id')->nullable()->after('pay_rate')->constrained('accounts')->onDelete('set null');
            $table->unsignedTinyInteger('pay_day')->nullable()->after('pay_account_id'); // day of month for monthly
            $table->boolean('pay_auto')->default(true)->after('pay_day');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropForeign(['pay_account_id']);
            $table->dropColumn(['pay_type', 'pay_rate', 'pay_account_id', 'pay_day', 'pay_auto']);
        });
    }
};