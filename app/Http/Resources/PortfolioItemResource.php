<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PortfolioItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'freelancer_id' => $this->freelancer_profile_id,
            'title' => $this->title,
            'description' => $this->description,
            'category' => $this->category,
            'project_url' => $this->project_url,
            'skills' => $this->skills ?? [],
            'completed_at' => $this->completed_at?->toDateString(),
            'visibility' => $this->visibility,
            'status' => $this->status,
            'views' => $this->views,
            'is_featured' => (bool) $this->is_featured,
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($media) => [
                'id' => $media->id,
                'media_type' => $media->media_type,
                'original_name' => $media->original_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'url' => asset('storage/'.$media->path),
                'sort_order' => $media->sort_order,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
