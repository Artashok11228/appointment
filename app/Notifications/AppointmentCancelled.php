<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class AppointmentCancelled extends AppointmentNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Appointment cancelled')
            ->line("Your {$this->serviceName()} appointment on {$this->startsAt()} was cancelled.");
    }
}
