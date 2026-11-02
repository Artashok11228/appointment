<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminStatsResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'by_status' => $this->resource['by_status'],
            'bookings_per_day' => $this->resource['bookings_per_day'],
            'top_services' => $this->resource['top_services'],
            'cancellation_rate' => $this->resource['cancellation_rate'],
        ];
    }
}
