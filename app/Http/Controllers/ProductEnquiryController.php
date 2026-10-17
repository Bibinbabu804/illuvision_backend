<?php

namespace App\Http\Controllers;
use App\Support\Events\Notifications\ProductEnquirySubmitted;
use App\Models\Product;
use App\Models\ProductEnquiry;
use Illuminate\Http\Request;

class ProductEnquiryController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'product_id' => 'required|exists:products,id',
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'message' => 'required|string',
        ]);

        try {
            $enquiry = ProductEnquiry::create($validated);

            // Fire notification event
            ProductEnquirySubmitted::dispatch($enquiry);

            return $this->successResponse($enquiry, 'Enquiry submitted successfully', 201);
        } catch (\Exception $e) {
            \Log::error('Product enquiry submission failed: ' . $e->getMessage());
            return $this->errorResponse('Failed to submit enquiry. Please try again.', 500);
        }
    }
}
