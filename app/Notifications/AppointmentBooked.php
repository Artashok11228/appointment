<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;

// After commit so a rolled-back booking never produces an email.
class AppointmentBooked extends AppointmentNotification implements ShouldQueueAfterCommit
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Appointment booked')
            ->line("Your {$this->serviceName()} appointment is booked for {$this->startsAt()}.");
    }
}
