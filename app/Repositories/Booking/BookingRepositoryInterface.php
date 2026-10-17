<?php

namespace App\Repositories\Booking;

use App\Models\Booking;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface BookingRepositoryInterface
{
    public function findById(int $id): ?Booking;
    public function findByReference(string $reference): ?Booking;
    public function getPaginated(array $filters = [], int $perPage = 15): LengthAwarePaginator;
    public function create(array $data): Booking;
    public function update(int $id, array $data): Booking;
    public function updateStatus(int $id, string $status): Booking;
}
