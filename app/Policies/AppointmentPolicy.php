<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Role;
use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $this->owns($user, $appointment);
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->owns($user, $appointment);
    }

    public function reschedule(User $user, Appointment $appointment): bool
    {
        return $this->owns($user, $appointment);
    }

    private function owns(User $user, Appointment $appointment): bool
    {
        return $user->role === Role::Admin || $appointment->user_id === $user->id;
    }
}
