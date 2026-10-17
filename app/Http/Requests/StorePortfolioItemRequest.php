<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePortfolioItemRequest extends FormRequest
{
    public function authorize(): bool { return (bool) $this->user(); }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:10000',
            'category' => 'nullable|string|max:100',
            'project_url' => 'nullable|url|max:1000',
            'skills' => 'nullable|array|max:30',
            'skills.*' => 'string|max:80',
            'completed_at' => 'nullable|date',
            'visibility' => ['nullable', Rule::in(['public', 'private'])],
            'status' => ['nullable', Rule::in(['draft', 'published', 'archived'])],
        ];
    }
}
