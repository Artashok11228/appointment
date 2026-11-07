<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Notification;

// Queued after commit so a rolled-back booking never produces an email.
abstract class AppointmentNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit
{
    use Queueable;

    public function __construct(public Appointment $appointment) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    protected function serviceName(): string
    {
        return $this->appointment->service->name;
    }

    protected function startsAt(): string
    {
        return $this->appointment->starts_at->format('Y-m-d H:i');
    }
}
