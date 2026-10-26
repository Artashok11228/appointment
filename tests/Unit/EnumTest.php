<?php

namespace Tests\Unit;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use PHPUnit\Framework\TestCase;

class EnumTest extends TestCase
{
    public function test_roles_are_admin_and_customer(): void
    {
        $this->assertSame(
            ['admin', 'customer'],
            array_column(Role::cases(), 'value'),
        );
    }

    public function test_appointment_statuses_are_confirmed_cancelled_and_completed(): void
    {
        $this->assertSame(
            ['confirmed', 'cancelled', 'completed'],
            array_column(AppointmentStatus::cases(), 'value'),
        );
    }
}
