<?php

namespace App\Http\Requests;

class UpdateFreelancerServiceRequest extends StoreFreelancerServiceRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        foreach (['title', 'description', 'category', 'subcategory', 'skills', 'featured_image', 'status'] as $field) {
            $rules[$field] = preg_replace('/^required\\|/', 'sometimes|', $rules[$field]);
        }
        $rules['skills.*'] = 'sometimes|string|max:80';
        $rules['packages'] = 'sometimes|array|min:1|max:3';
        return $rules;
    }
}
