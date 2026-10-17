<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FreelancerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar' => $this->user->avatar ? (str_starts_with($this->user->avatar, 'http') || str_starts_with($this->user->avatar, '/') ? $this->user->avatar : asset('storage/'.$this->user->avatar)) : null,
            ]),
            'professional_title' => $this->professional_title,
            'bio' => $this->bio,
            'location' => $this->location,
            'hourly_rate' => $this->hourly_rate !== null ? (float) $this->hourly_rate : null,
            'experience_years' => $this->experience_years,
            'languages' => $this->languages ?? [],
            'availability' => $this->availability,
            'response_time' => $this->response_time,
            'seller_level' => $this->seller_level,
            'social_links' => $this->social_links ?? [],
            'verification_status' => $this->verification_status,
            'rating' => (float) $this->rating,
            'total_reviews' => $this->total_reviews,
            'total_projects' => $this->total_projects,
            'total_earnings' => (float) $this->total_earnings,
            'skills' => FreelancerSkillResource::collection($this->whenLoaded('skills')),
            'services' => FreelancerServiceResource::collection($this->whenLoaded('services')),
            'portfolio' => PortfolioItemResource::collection($this->whenLoaded('portfolioItems')),
            'reviews' => $this->whenLoaded('reviews', fn () => $this->reviews->map(fn ($r) => [
                'id' => $r->id,
                'rating' => (int) $r->rating,
                'review' => $r->review,
                'client' => $r->client ? ['name' => $r->client->name, 'avatar' => $r->client->avatar] : null,
                'project_title' => $r->project?->title,
                'created_at' => $r->created_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
