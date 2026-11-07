<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Console\Command;

class CompletePastAppointments extends Command
{
    protected $signature = 'appointments:complete-past';

    protected $description = 'Mark confirmed appointments that have already ended as completed';

    public function handle(): int
    {
        $completed = Appointment::query()
            ->where('status', AppointmentStatus::Confirmed)
            ->where('ends_at', '<=', now())
            ->update(['status' => AppointmentStatus::Completed]);

        $this->info("Completed {$completed} appointment(s).");

        return self::SUCCESS;
    }
}
