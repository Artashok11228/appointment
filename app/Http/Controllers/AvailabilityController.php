<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ListAvailableSlots;
use App\Http\Requests\AvailabilityRequest;
use App\Models\Service;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class AvailabilityController extends Controller
{
    public function __invoke(
        AvailabilityRequest $request,
        Service $service,
        ListAvailableSlots $slots,
    ): JsonResponse {
        $day = Carbon::createFromFormat('Y-m-d', $request->validated('date'))->startOfDay();

        $data = array_map(fn (array $slot): array => [
            'starts_at' => $slot['starts_at']->toIso8601String(),
            'ends_at' => $slot['ends_at']->toIso8601String(),
        ], $slots->on($service, $day));

        return response()->json(['data' => $data]);
    }
}
