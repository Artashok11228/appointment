<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Notifications\Messages\MailMessage;

class AppointmentReminder extends AppointmentNotification
{
    public string $occurrenceStartsAt;

    public function __construct(Appointment $appointment)
    {
        parent::__construct($appointment);

        $this->occurrenceStartsAt = $appointment->starts_at->format('Y-m-d H:i:s');
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $appointment = $this->appointment->fresh();

        if ($appointment === null) {
            return false;
        }

        if ($appointment->status !== AppointmentStatus::Confirmed) {
            return false;
        }

        if ($appointment->ends_at->lessThanOrEqualTo(now())) {
            return false;
        }

        return $appointment->starts_at->format('Y-m-d H:i:s') === $this->occurrenceStartsAt;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Appointment reminder')
            ->line("Your {$this->serviceName()} appointment starts at {$this->startsAt()}.");
    }
}
