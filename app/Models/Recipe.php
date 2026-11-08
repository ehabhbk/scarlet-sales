<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Recipe extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'restaurant_id',
        'product_id',
        'name',
        'yield_qty',
        'yield_unit_id',
        'total_cost',
        'instructions',
        'version',
        'is_active',
    ];

    protected $casts = [
        'yield_qty' => 'decimal:4',
        'total_cost' => 'decimal:4',
        'version' => 'integer',
        'is_active' => 'boolean',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function yieldUnit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class, 'yield_unit_id');
    }

    public function ingredients()
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function versions()
    {
        return $this->hasMany(RecipeVersion::class)->orderBy('version', 'desc');
    }
}

class RecipeIngredient extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipe_id',
        'inventory_item_id',
        'unit_id',
        'quantity',
        'unit_cost',
        'total_cost',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:4',
        'unit_cost' => 'decimal:4',
        'total_cost' => 'decimal:4',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(InventoryUnit::class);
    }
}

class RecipeVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'recipe_id',
        'version',
        'snapshot',
        'total_cost',
        'changed_by',
        'change_note',
    ];

    protected $casts = [
        'version' => 'integer',
        'snapshot' => 'array',
        'total_cost' => 'decimal:4',
        'changed_by' => 'integer',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
