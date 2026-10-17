<?php

namespace App\Domain\Availability\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

class FleetCapacityExceededException extends Exception
{
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'Fleet Capacity Exceeded',
            'message' => $this->getMessage() ?: 'The requested vehicle quantity exceeds the available fleet inventory.',
            'status' => 422,
        ], 422);
    }
}
