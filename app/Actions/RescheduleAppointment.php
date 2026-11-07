<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AppointmentStatus;
use App\Exceptions\AppointmentUnavailable;
use App\Exceptions\BookingConflict;
use App\Models\Appointment;
use App\Models\Service;
use App\Notifications\AppointmentRescheduled;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class RescheduleAppointment
{
    public function __construct(private ListAvailableSlots $slots) {}

    public function handle(Appointment $appointment, CarbonInterface $startsAt): Appointment
    {
        return DB::transaction(function () use ($appointment, $startsAt): Appointment {
            $service = Service::query()->whereKey($appointment->service_id)->lockForUpdate()->firstOrFail();
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AppointmentStatus::Confirmed) {
                throw new BookingConflict('Only a confirmed appointment can be rescheduled.');
            }

            $endsAt = $startsAt->copy()->addMinutes($service->duration_minutes);

            if (! $this->slots->isFree($service, $startsAt, $endsAt, ignoring: $locked)) {
                throw new AppointmentUnavailable;
            }

            $locked->update([
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'reminded_at' => null,
            ]);
            $locked->user->notify(new AppointmentRescheduled($locked->load('service')));

            return $locked;
        });
    }
}
