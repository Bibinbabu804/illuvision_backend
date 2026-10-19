<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FreelancerProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $activeUnavail = $this->activeUnavailability();
        $mediaList = $this->relationLoaded('profileMedia')
            ? $this->profileMedia->map(fn ($m) => [
                'id' => $m->id,
                'path' => $m->path,
                'url' => asset('storage/'.$m->path),
                'is_primary' => (bool) $m->is_primary,
                'sort_order' => (int) $m->sort_order,
                'moderation_status' => $m->moderation_status,
                'original_name' => $m->original_name,
            ])
            : [];

        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'avatar' => $this->user->avatar ? (str_starts_with($this->user->avatar, 'http') || str_starts_with($this->user->avatar, '/') || str_starts_with($this->user->avatar, 'data:image') ? $this->user->avatar : asset('storage/'.$this->user->avatar)) : null,
            ]),
            'professional_title' => $this->professional_title,
            'bio' => $this->bio,
            'location' => $this->location,
            'hourly_rate' => $this->hourly_rate !== null ? (float) $this->hourly_rate : null,
            'experience_years' => $this->experience_years,
            'languages' => $this->languages ?? [],
            'availability' => $this->availability,
            'is_currently_unavailable' => (bool) $activeUnavail,
            'unavailable_until' => $activeUnavail?->to_date?->format('Y-m-d'),
            'unavailable_reason' => $activeUnavail?->reason,
            'active_unavailability' => $activeUnavail ? [
                'id' => $activeUnavail->id,
                'from_date' => $activeUnavail->from_date?->format('Y-m-d'),
                'to_date' => $activeUnavail->to_date?->format('Y-m-d'),
                'reason' => $activeUnavail->reason,
            ] : null,
            'unavailabilities' => $this->whenLoaded('unavailabilities', fn () => $this->unavailabilities->map(fn ($u) => [
                'id' => $u->id,
                'from_date' => $u->from_date?->format('Y-m-d'),
                'to_date' => $u->to_date?->format('Y-m-d'),
                'reason' => $u->reason,
            ])),
            'response_time' => $this->response_time,
            'response_time_seconds' => $this->response_time_seconds,
            'response_time_text' => $this->response_time_seconds
                ? \App\Services\Freelance\ResponseTimeCalculatorService::format($this->response_time_seconds)
                : ($this->response_time && $this->response_time !== 'Response time not available yet.'
                    ? (str_starts_with(strtolower($this->response_time), 'within') || str_starts_with(strtolower($this->response_time), 'usually')
                        ? $this->response_time
                        : 'Within ' . strtolower($this->response_time))
                    : 'Within 1 hour'),
            'seller_level' => $this->seller_level ?: 'Vetted Pro',
            'social_links' => $this->social_links ?? [],
            'verification_status' => $this->verification_status,
            'rating' => (float) $this->rating,
            'total_reviews' => $this->total_reviews,
            'total_projects' => $this->total_projects,
            'total_earnings' => (float) $this->total_earnings,
            'profile_media' => $mediaList,
            'skills' => FreelancerSkillResource::collection($this->whenLoaded('skills')),
            'services' => FreelancerServiceResource::collection($this->whenLoaded('services')),
            'portfolio' => PortfolioItemResource::collection($this->whenLoaded('portfolioItems')),
            'reviews' => $this->whenLoaded('reviews', fn () => $this->reviews->map(fn ($r) => [
                'id' => $r->id,
                'rating' => (int) $r->rating,
                'review' => $r->review,
                'client' => $r->client ? ['name' => $r->client->name, 'avatar' => $r->client->avatar] : null,
                'project_title' => $r->project?->title,
                'reply' => $r->relationLoaded('reply') && $r->reply ? [
                    'id' => $r->reply->id,
                    'reply' => $r->reply->reply,
                    'created_at' => $r->reply->created_at?->toIso8601String(),
                ] : ($r->reply ? [
                    'id' => $r->reply->id,
                    'reply' => $r->reply->reply,
                    'created_at' => $r->reply->created_at?->toIso8601String(),
                ] : null),
                'created_at' => $r->created_at?->toIso8601String(),
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
