<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductSize extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'product_id',
        'name',
        'price_modifier',
        'sort_order',
        'is_default',
    ];

    protected $casts = [
        'price_modifier' => 'decimal:4',
        'sort_order' => 'integer',
        'is_default' => 'boolean',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}

class ProductModifier extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'restaurant_id',
        'product_id',
        'category_id',
        'name',
        'is_required',
        'min_selection',
        'max_selection',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'min_selection' => 'integer',
        'max_selection' => 'integer',
        'sort_order' => 'integer',
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

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function options()
    {
        return $this->hasMany(ModifierOption::class)->orderBy('sort_order');
    }
}

class ModifierOption extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'modifier_id',
        'name',
        'price_modifier',
        'sort_order',
        'is_default',
    ];

    protected $casts = [
        'price_modifier' => 'decimal:4',
        'sort_order' => 'integer',
        'is_default' => 'boolean',
    ];

    public function modifier(): BelongsTo
    {
        return $this->belongsTo(ProductModifier::class);
    }
}
