<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AppointmentStatus;
use App\Exceptions\AppointmentUnavailable;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CreateAppointment
{
    public function __construct(private ListAvailableSlots $slots) {}

    public function handle(User $customer, Service $service, CarbonInterface $startsAt): Appointment
    {
        return DB::transaction(function () use ($customer, $service, $startsAt): Appointment {
            // Two bookings for one service have to share this row before either overlap check finishes.
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();
            $start = Carbon::instance($startsAt);
            $ends = $start->copy()->addMinutes($locked->duration_minutes);

            if (! $this->slots->aligns($locked, $start) || $this->conflicts($locked, $start, $ends)) {
                throw new AppointmentUnavailable;
            }

            $appointment = Appointment::query()->create([
                'user_id' => $customer->id,
                'service_id' => $locked->id,
                'starts_at' => $start,
                'ends_at' => $ends,
                'status' => AppointmentStatus::Confirmed,
            ]);

            $customer->notify(new AppointmentBooked($appointment->load('service')));

            return $appointment;
        });
    }

    private function conflicts(Service $service, CarbonInterface $startsAt, CarbonInterface $endsAt): bool
    {
        return Appointment::query()
            ->overlapping($service->id, $startsAt, $endsAt)
            ->lockForUpdate()
            ->exists();
    }
}
