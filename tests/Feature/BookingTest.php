<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BookingTest extends TestCase
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

    public function test_a_customer_can_book_an_open_slot(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-02 09:00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-11-02T09:00:00+00:00')
            ->assertJsonPath('data.ends_at', '2026-11-02T10:00:00+00:00')
            ->assertJsonPath('data.status', 'confirmed');

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_a_second_booking_for_the_same_slot_is_rejected(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $payload = [
            'service_id' => $service->id,
            'starts_at' => '2026-11-02 09:00:00',
        ];

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', $payload)
            ->assertCreated();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_a_slot_outside_the_grid_is_rejected(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-02 09:15:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_two_services_can_be_booked_at_the_same_time(): void
    {
        $customer = User::factory()->customer()->create();
        $haircut = Service::factory()->create(['duration_minutes' => 60]);
        $shave = Service::factory()->create(['duration_minutes' => 60]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $haircut->id,
                'starts_at' => '2026-11-02 09:00:00',
            ])
            ->assertCreated();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $shave->id,
                'starts_at' => '2026-11-02 09:00:00',
            ])
            ->assertCreated();

        $this->assertDatabaseCount('appointments', 2);
    }

    public function test_a_closed_day_cannot_be_booked(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-01 09:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');
    }

    public function test_a_missing_service_is_rejected(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => 9999,
                'starts_at' => '2026-11-02 09:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service_id');
    }

    public function test_a_guest_cannot_book(): void
    {
        $service = Service::factory()->create();

        $this->postJson('/api/appointments', [
            'service_id' => $service->id,
            'starts_at' => '2026-11-02 09:00:00',
        ])->assertUnauthorized();
    }
}
