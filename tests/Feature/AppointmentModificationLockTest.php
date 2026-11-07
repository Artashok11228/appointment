<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AppointmentModificationLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'booking.opens_at' => '09:00',
            'booking.closes_at' => '17:00',
            'booking.open_weekdays' => [1, 2, 3, 4, 5],
            'booking.modification_lock_minutes' => 120,
        ]);

        Carbon::setTestNow('2026-11-02 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_customer_cannot_cancel_inside_the_modification_lock_window(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-02 09:30:00',
            'ends_at' => '2026-11-02 10:30:00',
        ]);
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This appointment can no longer be cancelled.',
            );

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->refresh()->status);
        Notification::assertNothingSent();
    }

    public function test_a_customer_cannot_reschedule_inside_the_modification_lock_window(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 09:30:00',
            'ends_at' => '2026-11-02 10:30:00',
        ]);
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", [
                'starts_at' => '2026-11-02 14:00:00',
            ])
            ->assertConflict()
            ->assertJsonPath(
                'message',
                'This appointment can no longer be rescheduled.',
            );

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'starts_at' => '2026-11-02 09:30:00',
            'status' => AppointmentStatus::Confirmed->value,
        ]);
        Notification::assertNothingSent();
    }

    public function test_a_customer_can_cancel_outside_the_modification_lock_window(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_an_admin_can_cancel_inside_the_modification_lock_window(): void
    {
        $admin = User::factory()->admin()->create();
        $appointment = Appointment::factory()->create([
            'starts_at' => '2026-11-02 09:30:00',
            'ends_at' => '2026-11-02 10:30:00',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_setting_the_lock_to_zero_disables_it_for_customers(): void
    {
        config(['booking.modification_lock_minutes' => 0]);

        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-02 09:30:00',
            'ends_at' => '2026-11-02 10:30:00',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_a_customer_cannot_cancel_an_in_progress_appointment(): void
    {
        Carbon::setTestNow('2026-11-02 09:30:00');

        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
        ]);
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertConflict()
            ->assertJsonPath('message', 'This appointment can no longer be cancelled.');

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->refresh()->status);
        Notification::assertNothingSent();
    }

    public function test_an_admin_can_cancel_an_ended_appointment_still_marked_confirmed(): void
    {
        Carbon::setTestNow('2026-11-02 10:15:00');

        $admin = User::factory()->admin()->create();
        $appointment = Appointment::factory()->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }
}
