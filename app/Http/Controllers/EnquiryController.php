<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Enquiry;
use App\Models\ContactEnquiry;

class EnquiryController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->search;
        $perPage = $request->per_page ?? 10;

        $query = ContactEnquiry::query();

        if ($search) {
            $query->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('subject', 'like', "%{$search}%")
                ->orWhere('message', 'like', "%{$search}%");
        }

        $enquiries = $query->paginate($perPage);

        return $this->paginatedResponse($enquiries, 'Enquiries fetched successfully');
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'email' => 'required|email|max:255',
                'phone' => 'required|string|max:255',
                'subject' => 'required|string|max:255',
                'message' => 'required|string',
            ]);
            $enquiry = Enquiry::create($validated);
            return $this->successResponse($enquiry, 'Enquiry created successfully', 201);

        } catch (\Exception $e) {
            \Log::info($e->getMessage());
            return $this->errorResponse('Enquiry creation failed', 500, $e->getMessage());
        }
    }

    public function show(Enquiry $enquiry)
    {
        return $this->successResponse($enquiry, 'Enquiry fetched successfully');
    }

    public function destroy(Enquiry $enquiry)
    {
        $enquiry->delete();
        return $this->successResponse($enquiry, 'Enquiry deleted successfully');
    }
}
