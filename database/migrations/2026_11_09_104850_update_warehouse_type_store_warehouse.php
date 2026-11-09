<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Update existing 'pos' type to 'warehouse'
        DB::statement("UPDATE warehouses SET type = 'warehouse' WHERE type = 'pos'");

        // Modify the enum to use 'warehouse' instead of 'pos'
        DB::statement("ALTER TABLE warehouses MODIFY COLUMN type ENUM('store','warehouse','both') NOT NULL DEFAULT 'warehouse'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE warehouses MODIFY COLUMN type ENUM('store','pos','both') NOT NULL DEFAULT 'store'");
    }
};