<?php

namespace App\Http\Controllers;
use App\Support\Events\Notifications\ContactEnquirySubmitted;
use App\Models\ContactEnquiry;
use Illuminate\Http\Request;

class ContactEnquiryController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'required|email|max:255',
            'message' => 'required|string',
        ]);

        try {
            $enquiry = ContactEnquiry::create([
                'name' => strip_tags($validated['name']),
                'phone' => strip_tags($validated['phone']),
                'email' => strip_tags($validated['email']),
                'message' => strip_tags($validated['message']),
            ]);

            // Fire notification event
            ContactEnquirySubmitted::dispatch($enquiry);

            return $this->successResponse(
                ['id' => $enquiry->id],
                'Your message has been submitted successfully. Our team will contact you shortly.',
                201
            );
        } catch (\Exception $e) {
            \Log::error('Contact enquiry submission failed', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->errorResponse('Failed to submit your enquiry. Please try again.', 500);
        }
    }
}
