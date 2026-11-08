<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_sizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Small, Medium, Large
            $table->decimal('price_modifier', 12, 4)->default(0);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id']);
            $table->unique(['product_id', 'name']);
        });

        Schema::create('product_modifiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->foreignId('product_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('name'); // e.g. Extra Cheese, Cooking Level
            $table->boolean('is_required')->default(false);
            $table->integer('min_selection')->default(0);
            $table->integer('max_selection')->default(1);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id']);
            $table->index(['category_id']);
        });

        Schema::create('modifier_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('modifier_id')->constrained('product_modifiers')->onDelete('cascade');
            $table->string('name');
            $table->decimal('price_modifier', 12, 4)->default(0);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['modifier_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('product_modifiers');
        Schema::dropIfExists('product_sizes');
    }
};
