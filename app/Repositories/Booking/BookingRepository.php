<?php

namespace App\Repositories\Booking;

use App\Models\Booking;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use App\Repositories\Booking\BookingRepositoryInterface;

class BookingRepository implements BookingRepositoryInterface
{
    public function findById(int $id): ?Booking
    {
        return Booking::with(['bookingScreens.screen', 'user', 'pricingBreakdowns'])->find($id);
    }

    public function findByReference(string $reference): ?Booking
    {
        return Booking::with(['bookingScreens.screen', 'user', 'pricingBreakdowns'])
            ->where('booking_reference', $reference)
            ->first();
    }

    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = Booking::with(['bookingScreens.screen', 'user']);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('booking_reference', 'like', "%{$search}%")
                  ->orWhere('campaign_title', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('id', 'desc')->paginate($perPage);
    }

    public function create(array $data): Booking
    {
        return Booking::create($data);
    }

    public function update(int $id, array $data): Booking
    {
        $booking = Booking::findOrFail($id);
        $booking->update($data);
        return $booking->fresh(['bookingScreens.screen', 'user']);
    }

    public function updateStatus(int $id, string $status): Booking
    {
        $booking = Booking::findOrFail($id);
        $booking->update(['status' => $status]);
        return $booking;
    }
}
