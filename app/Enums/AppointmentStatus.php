<?php

declare(strict_types=1);

namespace App\Enums;

enum AppointmentStatus: string
{
    case Confirmed = 'confirmed';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
}
