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
        $queued = 0;

        $this->due()->each(function (Appointment $appointment) use (&$queued): void {
            if ($this->remind($appointment)) {
                $queued++;
            }
        });

        $this->info("Queued {$queued} reminder(s).");

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

    private function remind(Appointment $appointment): bool
    {
        // Reminder mail is queued in this same transaction (not after commit),
        // so a failed enqueue rolls reminded_at back and the next run can retry.
        return DB::transaction(function () use ($appointment): bool {
            $locked = Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->first();

            if ($locked === null || ! $this->stillDue($locked)) {
                return false;
            }

            $locked->update(['reminded_at' => now()]);
            $locked->loadMissing(['user', 'service']);
            $locked->user->notify(new AppointmentReminder($locked));

            return true;
        });
    }

    private function stillDue(Appointment $appointment): bool
    {
        $until = now()->addHours((int) config('booking.reminder_hours'));

        return $appointment->status === AppointmentStatus::Confirmed
            && $appointment->reminded_at === null
            && $appointment->starts_at->between(now(), $until);
    }
}
