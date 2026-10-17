<?php

namespace App\Http\Controllers;
use App\Support\Events\Notifications\ContactEnquiryAssigned;
use App\Support\Events\Notifications\ContactEnquiryClosed;
use App\Models\ContactEnquiry;
use App\Models\User;
use Illuminate\Http\Request;

class AdminContactEnquiryController extends Controller
{
    public function index(Request $request)
    {
        $search = $request->search;
        $perPage = $request->per_page ?? 10;
        $status = $request->status;
        $assignedTo = $request->assigned_to;
        $dateFrom = $request->date_from;
        $dateTo = $request->date_to;
        $sortBy = $request->sort_by ?? 'created_at';
        $sortOrder = $request->sort_order ?? 'desc';

        $query = ContactEnquiry::with('assignedUser');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($assignedTo) {
            $query->where('assigned_to', $assignedTo);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }

        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $enquiries = $query->orderBy($sortBy, $sortOrder)->paginate($perPage);

        return $this->paginatedResponse($enquiries, 'Contact enquiries fetched successfully');
    }

    public function show(ContactEnquiry $contactEnquiry)
    {
        $contactEnquiry->load('assignedUser');
        return $this->successResponse($contactEnquiry, 'Contact enquiry fetched successfully');
    }

    public function update(Request $request, ContactEnquiry $contactEnquiry)
    {
        $validated = $request->validate([
            'status' => 'sometimes|required|in:new,contacted,in_progress,closed,spam',
            'internal_notes' => 'nullable|string',
        ]);

        $oldStatus = $contactEnquiry->status;
        $contactEnquiry->update($validated);

        // Fire closed event if status changed to closed
        if ($validated['status'] === 'closed' && $oldStatus !== 'closed') {
            ContactEnquiryClosed::dispatch($contactEnquiry);
        }

        return $this->successResponse($contactEnquiry, 'Contact enquiry updated successfully');
    }

    public function assign(Request $request, ContactEnquiry $contactEnquiry)
    {
        $validated = $request->validate([
            'assigned_to' => 'required|exists:users,id',
        ]);

        $contactEnquiry->update([
            'assigned_to' => $validated['assigned_to'],
        ]);

        $user = User::find($validated['assigned_to']);
        if ($user) {
            ContactEnquiryAssigned::dispatch($contactEnquiry, $request->user());
        }

        $contactEnquiry->load('assignedUser');

        return $this->successResponse($contactEnquiry, 'Contact enquiry assigned successfully');
    }

    public function status(Request $request, ContactEnquiry $contactEnquiry)
    {
        $validated = $request->validate([
            'status' => 'required|in:new,contacted,in_progress,closed,spam',
        ]);

        $oldStatus = $contactEnquiry->status;
        $contactEnquiry->update(['status' => $validated['status']]);

        if ($validated['status'] === 'closed' && $oldStatus !== 'closed') {
            ContactEnquiryClosed::dispatch($contactEnquiry);
        }

        return $this->successResponse($contactEnquiry, 'Contact enquiry status updated successfully');
    }

    public function notes(Request $request, ContactEnquiry $contactEnquiry)
    {
        $validated = $request->validate([
            'internal_notes' => 'required|string',
        ]);

        $contactEnquiry->update([
            'internal_notes' => $validated['internal_notes'],
        ]);

        return $this->successResponse($contactEnquiry, 'Internal notes updated successfully');
    }
}
