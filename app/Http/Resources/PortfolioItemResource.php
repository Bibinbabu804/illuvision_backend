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
            'category_id' => $this->category_id,
            'category_model' => $this->whenLoaded('categoryModel', fn () => $this->categoryModel ? [
                'id' => $this->categoryModel->id,
                'name' => $this->categoryModel->name,
                'slug' => $this->categoryModel->slug,
            ] : null),
            'project_url' => $this->project_url,
            'skills' => $this->skills ?? [],
            'completed_at' => $this->completed_at?->toDateString(),
            'visibility' => $this->visibility,
            'status' => $this->status,
            'moderation_status' => $this->moderation_status,
            'moderation_feedback' => $this->moderation_feedback,
            'views' => $this->views,
            'is_featured' => (bool) $this->is_featured,
            'media' => $this->whenLoaded('media', fn () => $this->media->map(fn ($media) => [
                'id' => $media->id,
                'media_type' => $media->media_type,
                'original_name' => $media->original_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'url' => str_starts_with($media->path, 'http') ? $media->path : asset('storage/'.$media->path),
                'path' => $media->path,
                'thumbnail_url' => $media->thumbnail_path ? (str_starts_with($media->thumbnail_path, 'http') ? $media->thumbnail_path : asset('storage/'.$media->thumbnail_path)) : null,
                'is_cover' => (bool) $media->is_cover,
                'sort_order' => $media->sort_order,
                'moderation_status' => $media->moderation_status,
            ])),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
