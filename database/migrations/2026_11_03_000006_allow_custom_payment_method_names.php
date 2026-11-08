<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('method', 50)->change();
        });
    }

    public function down(): void
    {
        $supportedMethods = ['cash', 'card', 'wallet', 'bank_transfer', 'cheque'];
        if (DB::table('payments')->whereNotIn('method', $supportedMethods)->exists()) {
            throw new RuntimeException('Cannot restore fixed payment methods while custom methods exist.');
        }

        Schema::table('payments', function (Blueprint $table) use ($supportedMethods) {
            $table->enum('method', $supportedMethods)->change();
        });
    }
};
