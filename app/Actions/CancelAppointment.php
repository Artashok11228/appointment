<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\AppointmentStatus;
use App\Exceptions\BookingConflict;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppointmentCancelled;
use Illuminate\Support\Facades\DB;

class CancelAppointment
{
    public function handle(User $actor, Appointment $appointment): Appointment
    {
        return DB::transaction(function () use ($actor, $appointment): Appointment {
            $locked = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== AppointmentStatus::Confirmed) {
                throw new BookingConflict('Only a confirmed appointment can be cancelled.');
            }

            if (! $actor->isAdmin() && $locked->isLockedForCustomerModification()) {
                throw new BookingConflict('This appointment can no longer be cancelled.');
            }

            $locked->update(['status' => AppointmentStatus::Cancelled]);
            $locked->user->notify(new AppointmentCancelled($locked->load('service')));

            return $locked;
        });
    }
}
