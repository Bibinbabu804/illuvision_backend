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
            'category_id' => $this->category_id,
            'category_model' => $this->whenLoaded('categoryModel', fn () => $this->categoryModel ? [
                'id' => $this->categoryModel->id,
                'name' => $this->categoryModel->name,
                'slug' => $this->categoryModel->slug,
            ] : null),
            'subcategory' => $this->subcategory,
            'skills' => $this->skills ?? [],
            'highlights' => $this->highlights ?? [],
            'featured_image' => $this->featured_image ? (str_starts_with($this->featured_image, 'http') || str_starts_with($this->featured_image, '/') ? $this->featured_image : asset('storage/'.$this->featured_image)) : null,
            'gallery' => $this->gallery ? array_map(fn($img) => (str_starts_with($img, 'http') || str_starts_with($img, '/') ? $img : asset('storage/'.$img)), $this->gallery) : [],
            'video_url' => $this->video_url ?: ($this->video_path ? asset('storage/'.$this->video_path) : null),
            'video_path' => $this->video_path ? asset('storage/'.$this->video_path) : null,
            'video_thumbnail' => $this->video_thumbnail ? asset('storage/'.$this->video_thumbnail) : null,
            'video_status' => $this->video_status,
            'video_moderation_status' => $this->video_status ?? $this->moderation_status,
            'is_paused' => (bool) $this->is_paused,
            'pause_reason' => $this->pause_reason,
            'moderation_status' => $this->moderation_status,
            'moderation_feedback' => $this->moderation_feedback,
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
                    'avatar' => $this->freelancerProfile->user->avatar ? (str_starts_with($this->freelancerProfile->user->avatar, 'http') || str_starts_with($this->freelancerProfile->user->avatar, '/') || str_starts_with($this->freelancerProfile->user->avatar, 'data:image') ? $this->freelancerProfile->user->avatar : asset('storage/'.$this->freelancerProfile->user->avatar)) : null,
                ] : null,
                'professional_title' => $this->freelancerProfile->professional_title,
                'bio' => $this->freelancerProfile->bio,
                'location' => $this->freelancerProfile->location,
                'hourly_rate' => $this->freelancerProfile->hourly_rate,
                'skills' => $this->freelancerProfile->relationLoaded('skills') ? FreelancerSkillResource::collection($this->freelancerProfile->skills) : [],
                'is_currently_unavailable' => (bool) $this->freelancerProfile->activeUnavailability(),
                'unavailable_until' => $this->freelancerProfile->activeUnavailability()?->to_date?->format('Y-m-d'),
                'unavailability_reason' => $this->freelancerProfile->activeUnavailability()?->reason,
            ]),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
