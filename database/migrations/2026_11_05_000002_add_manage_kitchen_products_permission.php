<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['manage_kitchen_products'] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'sanctum']);
        }
    }

    public function down(): void
    {
        Permission::where('name', 'manage_kitchen_products')->delete();
    }
};
