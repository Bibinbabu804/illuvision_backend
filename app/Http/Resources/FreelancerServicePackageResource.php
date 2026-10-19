<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FreelancerServicePackageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'price' => (float) $this->price,
            'delivery_days' => $this->delivery_days,
            'revisions' => $this->revisions,
            'features' => $this->features ?? [],
        ];
    }
}
