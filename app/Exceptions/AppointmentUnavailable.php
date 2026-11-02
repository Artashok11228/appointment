<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AppointmentUnavailable extends Exception
{
    public function __construct(string $message = 'That time is no longer available.')
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => [
                'starts_at' => [$this->getMessage()],
            ],
        ], 422);
    }
}
