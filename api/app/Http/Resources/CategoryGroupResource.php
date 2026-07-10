<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryGroupResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'hidden' => $this->hidden,
            'sort_order' => $this->sort_order,
            'categories' => CategoryResource::collection($this->whenLoaded('categories')),
        ];
    }
}
