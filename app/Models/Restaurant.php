<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Restaurant extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'phone',
        'email',
        'address',
        'currency',
        'symbol',
        'tax_number',
        'tax_rate',
        'timezone',
        'locale',
        'logo',
        'favicon',
        'primary_color',
        'is_active',
    ];

    protected $casts = [
        'tax_rate' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function products()
    {
        return $this->hasMany(Product::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }

    public function areas()
    {
        return $this->hasMany(Area::class);
    }

    public function tables()
    {
        return $this->hasMany(Table::class);
    }

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function employees()
    {
        return $this->hasMany(Employee::class);
    }

    public function inventoryItems()
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function purchases()
    {
        return $this->hasMany(Purchase::class);
    }

    public function recipes()
    {
        return $this->hasMany(Recipe::class);
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }

    public function settings()
    {
        return $this->hasMany(Setting::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    public function kitchenStations()
    {
        return $this->hasMany(KitchenStation::class);
    }
}
