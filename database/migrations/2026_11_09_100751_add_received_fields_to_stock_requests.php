<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_requests', function (Blueprint $table) {
            $table->enum('status', ['pending', 'approved', 'completed'])->default('pending')->change();
            $table->unsignedBigInteger('received_by')->nullable()->after('approved_at');
            $table->timestamp('received_at')->nullable()->after('received_by');
        });

        Schema::table('stock_requests', function (Blueprint $table) {
            $table->foreign('received_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('stock_requests', function (Blueprint $table) {
            $table->dropForeign(['received_by']);
            $table->dropColumn(['received_by', 'received_at']);
            $table->enum('status', ['pending', 'approved', 'rejected', 'completed'])->default('pending')->change();
        });
    }
};