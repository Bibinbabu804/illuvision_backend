<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FreelancerServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'freelancer_id' => $this->freelancer_profile_id,
            'title' => $this->title,
            'slug' => $this->slug,
            'description' => $this->description,
            'category' => $this->category,
            'subcategory' => $this->subcategory,
            'skills' => $this->skills ?? [],
            'featured_image' => $this->featured_image ? (str_starts_with($this->featured_image, 'http') || str_starts_with($this->featured_image, '/') ? $this->featured_image : asset('storage/'.$this->featured_image)) : null,
            'gallery' => $this->gallery ? array_map(fn($img) => (str_starts_with($img, 'http') || str_starts_with($img, '/') ? $img : asset('storage/'.$img)), $this->gallery) : [],
            'status' => $this->status,
            'rating' => (float) $this->rating,
            'review_count' => $this->review_count,
            'orders_completed' => $this->orders_completed,
            'packages' => FreelancerServicePackageResource::collection($this->whenLoaded('packages')),
            'freelancer' => $this->whenLoaded('freelancerProfile', fn () => [
                'id' => $this->freelancerProfile->id,
                'user' => $this->freelancerProfile->relationLoaded('user') ? [
                    'id' => $this->freelancerProfile->user->id,
                    'name' => $this->freelancerProfile->user->name,
                    'avatar' => $this->freelancerProfile->user->avatar,
                ] : null,
                'professional_title' => $this->freelancerProfile->professional_title,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
