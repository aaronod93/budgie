<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'hidden' => $this->hidden,
            'sort_order' => $this->sort_order,
            'group_uuid' => $this->whenLoaded('group', fn () => $this->group->uuid),
        ];
    }
}
