<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Notifications\Messages\MailMessage;

class AppointmentCancelled extends AppointmentNotification implements ShouldQueueAfterCommit
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Appointment cancelled')
            ->line("Your {$this->serviceName()} appointment on {$this->startsAt()} was cancelled.");
    }
}
