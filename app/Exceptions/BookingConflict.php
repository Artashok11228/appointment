<?php

declare(strict_types=1);

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The request was valid, but the current state of the data does not allow it,
 * for example cancelling an appointment that is already cancelled.
 */
class BookingConflict extends Exception
{
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 409);
    }
}
