<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFreelancerProfileRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user(); }

    public function rules(): array
    {
        return [
            'professional_title' => 'nullable|string|max:255',
            'bio' => 'nullable|string|max:5000',
            'location' => 'nullable|string|max:255',
            'hourly_rate' => 'nullable|numeric|min:0|max:9999999999.99',
            'experience_years' => 'nullable|integer|min:0|max:100',
            'languages' => 'nullable|array|max:20',
            'languages.*' => 'string|max:80',
            'availability' => 'nullable|string|max:100',
            'response_time' => 'nullable|string|max:50',
            'seller_level' => 'nullable|string|max:50',
            'avatar' => 'nullable|string|max:1000',
            'social_links' => 'nullable|array',
            'social_links.*' => 'nullable|url|max:500',
        ];
    }
}
