<?php

namespace App\Http\Requests;

class UpdateFreelancerServiceRequest extends StoreFreelancerServiceRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['title', 'description', 'category', 'subcategory', 'skills', 'highlights', 'featured_image'] as $field) {
            if (isset($rules[$field]) && is_string($rules[$field])) {
                $rules[$field] = preg_replace('/^required\\|/', 'sometimes|', $rules[$field]);
            }
        }
        $rules['status'] = ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(['draft', 'active', 'paused', 'pending_approval', 'published'])];
        $rules['moderation_status'] = ['sometimes', 'nullable', \Illuminate\Validation\Rule::in(['draft', 'pending_review', 'approved', 'rejected', 'changes_requested'])];
        $rules['skills.*'] = 'sometimes|string|max:80';
        $rules['packages'] = 'sometimes|array|min:1|max:3';
        return $rules;
    }
}
