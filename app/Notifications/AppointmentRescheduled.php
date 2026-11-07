<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class AppointmentRescheduled extends AppointmentNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Appointment rescheduled')
            ->line("Your {$this->serviceName()} appointment was moved to {$this->startsAt()}.");
    }
}
