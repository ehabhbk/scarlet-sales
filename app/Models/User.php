<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    /**
     * Guard used by Spatie permissions. Without this property the package
     * falls back to auth.defaults.guard ("web") and cannot resolve the
     * "sanctum" roles stored in the database.
     */
    protected $guard_name = 'sanctum';

    public function getGuardName(): string
    {
        return 'sanctum';
    }

    public function getGuardNames(): Collection
    {
        return collect(['sanctum']);
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'email',
        'password',
        'restaurant_id',
        'phone',
        'avatar',
        'is_active',
        'vault_account_id',
        'pay_account_id',
        'revenue_account_id',
        'kitchen_station_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function restaurant()
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function shifts()
    {
        return $this->hasMany(Shift::class);
    }

    public function vaultAccount()
    {
        return $this->belongsTo(Account::class, 'vault_account_id');
    }

    public function payAccount()
    {
        return $this->belongsTo(Account::class, 'pay_account_id');
    }

    public function revenueAccount()
    {
        return $this->belongsTo(Account::class, 'revenue_account_id');
    }

    public function kitchenStation()
    {
        return $this->belongsTo(KitchenStation::class, 'kitchen_station_id');
    }

    public function userWarehouses()
    {
        return $this->hasMany(UserWarehouse::class);
    }

    public function warehouses()
    {
        return $this->belongsToMany(Warehouse::class, 'user_warehouses')
            ->withPivot(['can_sell', 'can_request_stock', 'can_approve_stock']);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
