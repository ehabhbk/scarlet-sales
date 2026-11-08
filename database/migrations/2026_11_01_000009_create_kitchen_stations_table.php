<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kitchen_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Grill, Salad, Drinks, Dessert
            $table->string('display_name')->nullable();
            $table->string('color')->default('#ef4444');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'is_active']);
        });

        Schema::create('order_kitchen_stations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->onDelete('cascade');
            $table->foreignId('station_id')->constrained('kitchen_stations')->onDelete('cascade');
            $table->integer('prep_time_minutes')->nullable(); // timer
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['station_id']);
            $table->index(['order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_kitchen_stations');
        Schema::dropIfExists('kitchen_stations');
    }
};
