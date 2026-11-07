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

        $gridStart = $candidates[0]['starts_at'];
        $gridEnd = $candidates[array_key_last($candidates)]['ends_at'];
        $booked = Appointment::query()
            ->overlapping($service->id, $gridStart, $gridEnd)
            ->get(['starts_at', 'ends_at']);

        return array_values(array_filter(
            $candidates,
            fn (array $slot): bool => ! $this->overlapsAny($booked, $slot['starts_at'], $slot['ends_at']),
        ));
    }

    /**
     * Meant to run inside a transaction that already holds the service row lock.
     */
    public function isFree(Service $service, CarbonInterface $startsAt, CarbonInterface $endsAt, ?Appointment $ignoring = null): bool
    {
        if (! $this->isOnGrid($service, $startsAt)) {
            return false;
        }

        return ! Appointment::query()
            ->overlapping($service->id, $startsAt, $endsAt, $ignoring?->id)
            ->lockForUpdate()
            ->exists();
    }

    private function isOnGrid(Service $service, CarbonInterface $startsAt): bool
    {
        foreach ($this->candidates($service, $startsAt) as $slot) {
            if ($slot['starts_at']->equalTo($startsAt)) {
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

        $closes = $day->copy()->setTimeFromTimeString((string) config('booking.closes_at'));
        $cursor = $day->copy()->setTimeFromTimeString((string) config('booking.opens_at'));
        $slots = [];

        while ($cursor->copy()->addMinutes($service->duration_minutes)->lessThanOrEqualTo($closes)) {
            if ($cursor->isFuture()) {
                $slots[] = [
                    'starts_at' => $cursor->copy(),
                    'ends_at' => $cursor->copy()->addMinutes($service->duration_minutes),
                ];
            }

            $cursor->addMinutes($service->duration_minutes);
        }

        return $slots;
    }

    /**
     * @param  Collection<int, Appointment>  $booked
     */
    private function overlapsAny(Collection $booked, CarbonInterface $startsAt, CarbonInterface $endsAt): bool
    {
        return $booked->contains(
            fn (Appointment $appointment): bool => $startsAt->lt($appointment->ends_at) && $endsAt->gt($appointment->starts_at),
        );
    }
}
