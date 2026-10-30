<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Notifications\AppointmentReminder;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SendAppointmentReminders extends Command
{
    protected $signature = 'appointments:send-reminders';

    protected $description = 'Queue reminder mail for confirmed appointments in the reminder window';

    public function handle(): int
    {
        $count = 0;

        $this->due()->each(function (Appointment $appointment) use (&$count): void {
            $this->remind($appointment);
            $count++;
        });

        $this->info("Queued {$count} reminder(s).");

        return self::SUCCESS;
    }

    private function due(): Builder
    {
        $until = now()->addHours((int) config('booking.reminder_hours'));

        return Appointment::query()
            ->with(['user', 'service'])
            ->where('status', AppointmentStatus::Confirmed)
            ->whereNull('reminded_at')
            ->whereBetween('starts_at', [now(), $until])
            ->orderBy('starts_at');
    }

    private function remind(Appointment $appointment): void
    {
        DB::transaction(function () use ($appointment): void {
            $appointment->update(['reminded_at' => now()]);

            $appointment->user->notify(new AppointmentReminder($appointment));
        });
    }
}
