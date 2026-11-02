<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AppointmentManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'booking.opens_at' => '09:00',
            'booking.closes_at' => '12:00',
            'booking.open_weekdays' => [1, 2, 3, 4, 5],
        ]);

        Carbon::setTestNow('2026-11-02 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_customer_lists_only_their_appointments_and_an_admin_lists_all(): void
    {
        $customer = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        $mine = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
        ]);
        Appointment::factory()->for($other)->for($service)->create([
            'starts_at' => '2026-11-03 09:00:00',
            'ends_at' => '2026-11-03 10:00:00',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mine->id);

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/appointments')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_customer_cannot_open_someone_elses_appointment(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/appointments/{$appointment->id}")
            ->assertForbidden();
    }

    public function test_a_customer_can_cancel_their_own_confirmed_appointment(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create([
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);
    }

    public function test_a_customer_cannot_cancel_someone_elses_appointment(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertForbidden();
    }

    public function test_a_completed_appointment_cannot_be_cancelled(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->completed()->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');
    }

    public function test_a_customer_can_reschedule_onto_a_free_slot(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", [
                'starts_at' => '2026-11-02 11:00:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-11-02T11:00:00+00:00')
            ->assertJsonPath('data.ends_at', '2026-11-02T12:00:00+00:00');
    }

    public function test_a_customer_cannot_reschedule_someone_elses_appointment(): void
    {
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", [
                'starts_at' => '2026-11-02 11:00:00',
            ])
            ->assertForbidden();
    }

    public function test_an_admin_can_cancel_a_customers_appointment(): void
    {
        $admin = User::factory()->admin()->create();
        $appointment = Appointment::factory()->create([
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
    }

    public function test_cancelling_frees_the_slot_for_someone_else(): void
    {
        $owner = User::factory()->customer()->create();
        $next = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $appointment = Appointment::factory()->for($owner)->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk();

        $this->actingAs($next, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-02 09:00:00',
            ])
            ->assertCreated();
    }

    public function test_a_guest_cannot_list_appointments(): void
    {
        $this->getJson('/api/appointments')->assertUnauthorized();
    }

    public function test_rescheduling_onto_a_taken_slot_is_rejected(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
        ]);
        Appointment::factory()->for($service)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", [
                'starts_at' => '2026-11-02 11:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertSame(
            '2026-11-02 09:00:00',
            $appointment->refresh()->starts_at->format('Y-m-d H:i:s'),
        );
    }
}
