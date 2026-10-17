<?php

namespace App\Http\Controllers;

use App\Models\ProductEnquiry;
use Illuminate\Http\Request;

class AdminProductEnquiryController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->search;
        $perPage = $request->per_page ?? 10;
        $status = $request->status;

        $query = ProductEnquiry::with('product');

        if ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('message', 'like', "%{$search}%");
        }

        if ($status) {
            $query->where('status', $status);
        }

        $enquiries = $query->latest()->paginate($perPage);

        return $this->paginatedResponse($enquiries, 'Product enquiries fetched successfully');
    }

    public function show(ProductEnquiry $productEnquiry)
    {
        $productEnquiry->load('product');
        return $this->successResponse($productEnquiry, 'Product enquiry fetched successfully');
    }

    public function update(Request $request, ProductEnquiry $productEnquiry)
    {
        $validated = $request->validate([
            'status' => 'sometimes|required|in:new,contacted,qualified,closed',
            'notes' => 'nullable|string',
        ]);

        $productEnquiry->update($validated);

        return $this->successResponse($productEnquiry, 'Product enquiry updated successfully');
    }

    public function destroy(ProductEnquiry $productEnquiry)
    {
        $productEnquiry->delete();

        return $this->successResponse(null, 'Product enquiry deleted successfully');
    }
}
