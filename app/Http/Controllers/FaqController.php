<?php

namespace App\Http\Controllers;

use App\Models\Faq;
use Illuminate\Http\Request;

class FaqController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->input('search');
        $isActive = $request->input('is_active');
        $perPage = $request->input('perPage', 10);

        $faqs = Faq::query()
            ->when($search, function ($query) use ($search) {
                $query->where('question', 'like', "%{$search}%")
                    ->orWhere('answer', 'like', "%{$search}%");
            })
            ->when($isActive !== null, function ($query) use ($isActive) {
                $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
            })
            ->orderBy('order')
            ->paginate($perPage);

        return $this->paginatedResponse($faqs, 'Faqs fetched successfully');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'question' => 'required|string',
            'answer' => 'required|string',
            'is_active' => 'boolean',
            'order' => 'integer',
        ]);

        $faq = Faq::create($validated);

        return $this->successResponse($faq, 'Faq created successfully', 201);
    }

    public function show(Faq $faq)
    {
        return $this->successResponse($faq, 'Faq fetched successfully');
    }

    public function update(Request $request, Faq $faq)
    {
        $validated = $request->validate([
            'question' => 'sometimes|required|string',
            'answer' => 'sometimes|required|string',
            'is_active' => 'boolean',
            'order' => 'integer',
        ]);

        $faq->update($validated);

        return $this->successResponse($faq, 'Faq updated successfully');
    }

    public function destroy(Faq $faq)
    {
        $faq->delete();
        return $this->successResponse($faq, 'Faq deleted successfully');
    }
}
