<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shift_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->string('name');
            $table->date('shift_date');
            $table->timestamps();
            $table->index(['restaurant_id', 'shift_date']);
        });

        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('shift_group_id')->nullable()->after('restaurant_id')
                ->constrained('shift_groups')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shift_group_id');
        });
        Schema::dropIfExists('shift_groups');
    }
};
