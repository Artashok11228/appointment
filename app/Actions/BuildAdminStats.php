<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BuildAdminStats
{
    /**
     * @return array{
     *     by_status: array<string, int>,
     *     bookings_per_day: list<array{date: string, total: int}>,
     *     top_services: list<array{service_id: int, name: string, total: int}>,
     *     cancellation_rate: float
     * }
     */
    public function handle(): array
    {
        return [
            'by_status' => $this->byStatus(),
            'bookings_per_day' => $this->bookingsPerDay(),
            'top_services' => $this->topServices(),
            'cancellation_rate' => $this->cancellationRate(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function byStatus(): array
    {
        $counts = Appointment::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $byStatus = [];

        foreach (AppointmentStatus::cases() as $status) {
            $byStatus[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $byStatus;
    }

    /**
     * @return list<array{date: string, total: int}>
     */
    private function bookingsPerDay(): array
    {
        $days = (int) config('booking.stats_days');
        $from = now()->subDays($days - 1)->startOfDay();

        $counts = Appointment::query()
            ->select(DB::raw('date(created_at) as day'), DB::raw('count(*) as total'))
            ->where('created_at', '>=', $from)
            ->groupBy('day')
            ->pluck('total', 'day');

        $series = [];

        for ($offset = $days - 1; $offset >= 0; $offset--) {
            $day = Carbon::today()->subDays($offset)->toDateString();
            $series[] = [
                'date' => $day,
                'total' => (int) ($counts[$day] ?? 0),
            ];
        }

        return $series;
    }

    /**
     * @return list<array{service_id: int, name: string, total: int}>
     */
    private function topServices(): array
    {
        return Appointment::query()
            ->join('services', 'services.id', '=', 'appointments.service_id')
            ->select(
                'appointments.service_id',
                'services.name',
                DB::raw('count(*) as total'),
            )
            ->groupBy('appointments.service_id', 'services.name')
            ->orderByDesc('total')
            ->limit((int) config('booking.top_services'))
            ->get()
            ->map(fn (object $service): array => [
                'service_id' => (int) $service->service_id,
                'name' => (string) $service->name,
                'total' => (int) $service->total,
            ])
            ->all();
    }

    private function cancellationRate(): float
    {
        $totals = Appointment::query()
            ->selectRaw(
                'count(*) as total, sum(case when status = ? then 1 else 0 end) as cancelled',
                [AppointmentStatus::Cancelled->value],
            )
            ->first();

        $total = (int) ($totals->total ?? 0);

        if ($total === 0) {
            return 0.0;
        }

        return round(((int) $totals->cancelled) / $total, 4);
    }
}
