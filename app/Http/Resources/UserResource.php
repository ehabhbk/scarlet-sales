<?php

namespace App\Http\Resources;

use App\Support\PublicMediaUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar' => PublicMediaUrl::make($this->avatar),
            'is_active' => (bool) $this->is_active,
            'vault_account_id' => $this->vault_account_id,
            'pay_account_id' => $this->pay_account_id,
            'revenue_account_id' => $this->revenue_account_id,
            'kitchen_station_id' => $this->kitchen_station_id,
            'kitchen_station' => $this->whenLoaded('kitchenStation', fn () => $this->kitchenStation ? [
                'id' => $this->kitchenStation->id,
                'name' => $this->kitchenStation->display_name ?: $this->kitchenStation->name,
            ] : null),
            'vault_account' => $this->whenLoaded('vaultAccount', fn () => $this->vaultAccount ? [
                'id' => $this->vaultAccount->id,
                'code' => $this->vaultAccount->code,
                'name' => $this->vaultAccount->name,
                'parent_id' => $this->vaultAccount->parent_id,
                'type' => $this->vaultAccount->type,
            ] : null),
            'pay_account' => $this->whenLoaded('payAccount', fn () => $this->payAccount ? [
                'id' => $this->payAccount->id,
                'code' => $this->payAccount->code,
                'name' => $this->payAccount->name,
                'type' => $this->payAccount->type,
            ] : null),
            'revenue_account' => $this->whenLoaded('revenueAccount', fn () => $this->revenueAccount ? [
                'id' => $this->revenueAccount->id,
                'code' => $this->revenueAccount->code,
                'name' => $this->revenueAccount->name,
                'parent_id' => $this->revenueAccount->parent_id,
                'type' => $this->revenueAccount->type,
            ] : null),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'guard_name' => $r->guard_name,
            ])),
            'permissions' => $this->when(
                $this->resource->exists,
                fn () => $this->getAllPermissions()->pluck('name')->values()
            ),
            'restaurant' => $this->whenLoaded('restaurant', fn () => $this->restaurant ? [
                'id' => $this->restaurant->id,
                'name' => $this->restaurant->name,
                'slug' => $this->restaurant->slug,
                'currency' => $this->restaurant->currency,
                'symbol' => $this->restaurant->symbol,
                'tax_rate' => (float) $this->restaurant->tax_rate,
                'logo' => PublicMediaUrl::make($this->restaurant->logo),
            ] : null),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
