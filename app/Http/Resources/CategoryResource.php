<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'image' => $this->image,
            'color' => $this->color,
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'is_featured' => (bool) $this->is_featured,
            'parent_id' => $this->parent_id,
            'children' => $this->whenLoaded('children', fn() => CategoryResource::collection($this->children)),
            'products_count' => $this->whenCounted('products'),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
