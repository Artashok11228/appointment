<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AppointmentStatus;
use App\Exceptions\AppointmentUnavailable;
use App\Models\Appointment;
use App\Models\Service;
use App\Notifications\AppointmentRescheduled;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RescheduleAppointment
{
    public function __construct(private ListAvailableSlots $slots) {}

    public function handle(Appointment $appointment, CarbonInterface $startsAt): Appointment
    {
        return DB::transaction(function () use ($appointment, $startsAt): Appointment {
            $locked = Service::query()->whereKey($appointment->service_id)->lockForUpdate()->firstOrFail();
            $appointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($appointment->status !== AppointmentStatus::Confirmed) {
                throw ValidationException::withMessages([
                    'status' => ['Only a confirmed appointment can be rescheduled.'],
                ]);
            }

            $start = Carbon::instance($startsAt);
            $ends = $start->copy()->addMinutes($locked->duration_minutes);
            $taken = Appointment::query()
                ->overlapping($locked->id, $start, $ends, $appointment->id)
                ->lockForUpdate()
                ->exists();

            if (! $this->slots->aligns($locked, $start) || $taken) {
                throw new AppointmentUnavailable;
            }

            $appointment->update([
                'starts_at' => $start,
                'ends_at' => $ends,
            ]);

            $appointment->user->notify(new AppointmentRescheduled($appointment->load('service')));

            return $appointment;
        });
    }
}
