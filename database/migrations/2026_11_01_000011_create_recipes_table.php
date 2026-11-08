<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->decimal('yield_qty', 12, 4)->default(1); // how many product units this recipe makes
            $table->foreignId('yield_unit_id')->nullable()->constrained('inventory_units')->onDelete('set null');
            $table->decimal('total_cost', 12, 4)->default(0);
            $table->text('instructions')->nullable();
            $table->integer('version')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'product_id']);
            $table->index(['is_active']);
        });

        Schema::create('recipe_ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->onDelete('cascade');
            $table->foreignId('inventory_item_id')->constrained()->onDelete('restrict');
            $table->foreignId('unit_id')->constrained('inventory_units')->onDelete('restrict');
            $table->decimal('quantity', 12, 4)->default(0);
            $table->decimal('unit_cost', 12, 4)->default(0); // snapshot cost at time of recipe
            $table->decimal('total_cost', 12, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['recipe_id']);
            $table->index(['inventory_item_id']);
            $table->unique(['recipe_id', 'inventory_item_id']);
        });

        Schema::create('recipe_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recipe_id')->constrained()->onDelete('cascade');
            $table->integer('version');
            $table->longText('snapshot'); // JSON snapshot of ingredients
            $table->decimal('total_cost', 12, 4)->default(0);
            $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('change_note')->nullable();
            $table->timestamps();

            $table->index(['recipe_id']);
            $table->unique(['recipe_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_versions');
        Schema::dropIfExists('recipe_ingredients');
        Schema::dropIfExists('recipes');
    }
};
