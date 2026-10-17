<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

abstract class Controller
{
    protected function successResponse(
        mixed $data = null,
        string $message = 'Request successful',
        int $statusCode = 200,
        array $extra = []
    ): JsonResponse {
        $response = [
            'status' => true,
            'message' => $message,
            'data' => $data,
        ];

        return response()->json(array_merge($response, $extra), $statusCode);
    }

    protected function paginatedResponse(
        LengthAwarePaginator $paginator,
        string $message = 'Records fetched successfully',
        int $statusCode = 200,
        array $extra = []
    ): JsonResponse {
        $meta = array_merge([
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ], $extra);

        return $this->successResponse(
            $paginator->items(),
            $message,
            $statusCode,
            ['meta' => $meta]
        );
    }

    protected function errorResponse(
        string $message = 'Request failed',
        int $statusCode = 400,
        mixed $errors = null
    ): JsonResponse {
        $response = [
            'status' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    protected function noContentResponse(): JsonResponse
    {
        return response()->json(null, 204);
    }
}
