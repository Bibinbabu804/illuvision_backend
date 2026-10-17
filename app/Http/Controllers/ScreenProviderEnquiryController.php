<?php

namespace App\Http\Controllers;
use App\Support\Events\Notifications\ScreenProviderEnquirySubmitted;
use App\Models\ScreenProviderEnquiry;
use Illuminate\Http\Request;

class ScreenProviderEnquiryController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'message' => 'required|string|max:5000',
        ]);

        try {
            $enquiry = ScreenProviderEnquiry::create([
                'name' => strip_tags($validated['name']),
                'phone' => strip_tags($validated['phone']),
                'email' => strip_tags($validated['email']),
                'message' => strip_tags($validated['message']),
                'status' => 'new',
            ]);

            ScreenProviderEnquirySubmitted::dispatch($enquiry);

            return $this->successResponse([
                'id' => $enquiry->id,
            ], 'Your enquiry has been submitted successfully. Our team will review your request and contact you shortly.', 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('ScreenProviderEnquiry submission failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);

            return $this->errorResponse('Failed to submit enquiry. Please try again later.', 500);
        }
    }
}
