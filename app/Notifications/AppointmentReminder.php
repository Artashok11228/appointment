<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class AppointmentReminder extends AppointmentNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Appointment reminder')
            ->line("Your {$this->serviceName()} appointment starts at {$this->startsAt()}.");
    }
}
