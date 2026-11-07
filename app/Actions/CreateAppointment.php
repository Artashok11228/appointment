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
use Illuminate\Support\Facades\DB;

class CreateAppointment
{
    public function __construct(private ListAvailableSlots $slots) {}

    public function handle(User $customer, int $serviceId, CarbonInterface $startsAt): Appointment
    {
        return DB::transaction(function () use ($customer, $serviceId, $startsAt): Appointment {
            // Two bookings for one service have to share this row before either overlap check finishes.
            $service = Service::query()->whereKey($serviceId)->lockForUpdate()->firstOrFail();
            $endsAt = $startsAt->copy()->addMinutes($service->duration_minutes);

            if (! $this->slots->isFree($service, $startsAt, $endsAt)) {
                throw new AppointmentUnavailable;
            }

            $appointment = Appointment::query()->create([
                'user_id' => $customer->id,
                'service_id' => $service->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => AppointmentStatus::Confirmed,
            ]);

            $customer->notify(new AppointmentBooked($appointment->load('service')));

            return $appointment;
        });
    }
}
