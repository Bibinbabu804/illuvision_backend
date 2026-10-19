<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFreelancerServiceRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user(); }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'required|string|max:10000',
            'category_id' => 'nullable|exists:freelance_categories,id',
            'category' => 'nullable|string|max:100',
            'subcategory' => 'nullable|string|max:100',
            'skills' => 'nullable|array|max:30',
            'skills.*' => 'string|max:80',
            'highlights' => 'nullable|array|max:30',
            'highlights.*' => 'string|max:255',
            'featured_image' => 'nullable|string',
            'video' => 'nullable|file|mimes:mp4,webm,mov|max:102400',
            'video_url' => 'nullable|string|max:500',
            'gallery' => 'nullable|array|max:10',
            'gallery.*' => 'nullable|string',
            'status' => ['nullable', Rule::in(['draft', 'active', 'paused', 'pending_approval', 'published'])],
            'moderation_status' => ['nullable', Rule::in(['draft', 'pending_review', 'approved', 'rejected', 'changes_requested'])],
            'packages' => 'required|array|min:1|max:3',
            'packages.*.name' => 'required|string|max:30',
            'packages.*.title' => 'nullable|string|max:120',
            'packages.*.description' => 'nullable|string|max:2000',
            'packages.*.price' => 'required|numeric|min:0.01|max:9999999999.99',
            'packages.*.delivery_days' => 'required|integer|min:1|max:3650',
            'packages.*.revisions' => 'nullable|integer|min:0|max:100',
            'packages.*.features' => 'nullable|array|max:50',
            'packages.*.features.*' => 'string|max:255',
        ];
    }
}
