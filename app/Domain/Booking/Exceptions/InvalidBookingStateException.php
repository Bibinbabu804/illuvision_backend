<?php

namespace App\Domain\Booking\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class InvalidBookingStateException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'Invalid Booking State Transition',
            'message' => $this->getMessage() ?: 'The requested status transition is invalid for the current booking state.',
            'status' => 422,
        ], 422);
    }
}
