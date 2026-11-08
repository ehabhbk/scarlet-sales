<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expenses', 'shift_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->foreignId('shift_id')->nullable()->after('restaurant_id')->constrained('shifts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('expenses', 'shift_id')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropForeign(['shift_id']);
                $table->dropColumn('shift_id');
            });
        }
    }
};