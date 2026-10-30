<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Notifications\AppointmentCancelled;
use Illuminate\Validation\ValidationException;

class CancelAppointment
{
    public function handle(Appointment $appointment): Appointment
    {
        if ($appointment->status !== AppointmentStatus::Confirmed) {
            throw ValidationException::withMessages([
                'status' => ['Only a confirmed appointment can be cancelled.'],
            ]);
        }

        $appointment->update([
            'status' => AppointmentStatus::Cancelled,
        ]);

        $appointment->user->notify(new AppointmentCancelled($appointment->load('service')));

        return $appointment;
    }
}
