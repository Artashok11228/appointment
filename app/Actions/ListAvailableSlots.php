<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Appointment;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class ListAvailableSlots
{
    /**
     * @return list<array{starts_at: Carbon, ends_at: Carbon}>
     */
    public function on(Service $service, CarbonInterface $day): array
    {
        $candidates = $this->candidates($service, $day);

        if ($candidates === []) {
            return [];
        }

        $first = $candidates[0]['starts_at'];
        $last = $candidates[array_key_last($candidates)]['ends_at'];
        $booked = Appointment::query()
            ->overlapping($service->id, $first, $last)
            ->get(['starts_at', 'ends_at']);

        return array_values(array_filter(
            $candidates,
            fn (array $slot): bool => ! $this->hits($booked, $slot['starts_at'], $slot['ends_at']),
        ));
    }

    public function aligns(Service $service, CarbonInterface $startsAt): bool
    {
        $start = Carbon::instance($startsAt);

        foreach ($this->candidates($service, $start) as $slot) {
            if ($slot['starts_at']->equalTo($start)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{starts_at: Carbon, ends_at: Carbon}>
     */
    private function candidates(Service $service, CarbonInterface $day): array
    {
        $day = Carbon::instance($day)->startOfDay();

        if (! in_array($day->dayOfWeekIso, config('booking.open_weekdays'), true)) {
            return [];
        }

        $opens = $day->copy()->setTimeFromTimeString((string) config('booking.opens_at'));
        $closes = $day->copy()->setTimeFromTimeString((string) config('booking.closes_at'));
        $cursor = $opens->copy();
        $slots = [];

        while ($cursor->copy()->addMinutes($service->duration_minutes)->lessThanOrEqualTo($closes)) {
            $ends = $cursor->copy()->addMinutes($service->duration_minutes);

            if ($cursor->greaterThan(now())) {
                $slots[] = [
                    'starts_at' => $cursor->copy(),
                    'ends_at' => $ends,
                ];
            }

            $cursor->addMinutes($service->duration_minutes);
        }

        return $slots;
    }

    /**
     * @param  Collection<int, Appointment>  $booked
     */
    private function hits(Collection $booked, CarbonInterface $startsAt, CarbonInterface $endsAt): bool
    {
        return $booked->contains(
            fn (Appointment $appointment): bool => $startsAt->lt($appointment->ends_at) && $endsAt->gt($appointment->starts_at),
        );
    }
}
