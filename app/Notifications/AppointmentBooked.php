<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;

class AppointmentBooked extends AppointmentNotification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Appointment booked')
            ->line("Your {$this->serviceName()} appointment is booked for {$this->startsAt()}.");
    }
}
