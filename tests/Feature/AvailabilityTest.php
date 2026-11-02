<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AvailabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'booking.opens_at' => '09:00',
            'booking.closes_at' => '11:00',
            'booking.open_weekdays' => [1, 2, 3, 4, 5],
        ]);

        Carbon::setTestNow('2026-11-02 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_guest_cannot_ask_for_availability(): void
    {
        $service = Service::factory()->create();

        $this->getJson("/api/services/{$service->id}/availability?date=2026-11-02")
            ->assertUnauthorized();
    }

    public function test_a_customer_sees_open_slots_for_a_weekday(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/services/{$service->id}/availability?date=2026-11-02")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.starts_at', '2026-11-02T09:00:00+00:00')
            ->assertJsonPath('data.0.ends_at', '2026-11-02T10:00:00+00:00')
            ->assertJsonPath('data.1.starts_at', '2026-11-02T10:00:00+00:00');
    }

    public function test_a_booked_slot_is_removed_and_a_cancelled_one_stays_open(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        Appointment::factory()->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);
        Appointment::factory()->for($service)->cancelled()->create([
            'starts_at' => '2026-11-02 10:00:00',
            'ends_at' => '2026-11-02 11:00:00',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/services/{$service->id}/availability?date=2026-11-02")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.starts_at', '2026-11-02T10:00:00+00:00');
    }

    public function test_a_closed_day_has_no_slots(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/services/{$service->id}/availability?date=2026-11-01")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_slot_that_overlaps_an_existing_appointment_is_hidden(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 30]);

        Appointment::factory()->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/services/{$service->id}/availability?date=2026-11-02")
            ->assertOk()
            ->assertJsonPath('data.0.starts_at', '2026-11-02T10:00:00+00:00')
            ->assertJsonMissing(['starts_at' => '2026-11-02T09:30:00+00:00']);
    }

    public function test_slots_that_have_already_started_are_hidden(): void
    {
        Carbon::setTestNow('2026-11-02 09:30:00');
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/services/{$service->id}/availability?date=2026-11-02")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.starts_at', '2026-11-02T10:00:00+00:00');
    }

    public function test_an_invalid_date_is_rejected(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create();

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/services/{$service->id}/availability?date=Monday")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
    }
}
