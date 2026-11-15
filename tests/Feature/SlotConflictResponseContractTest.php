<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Locks the slot-conflict API shape: 422 + message + errors.starts_at.
 * Status conflicts stay 409 with message only.
 */
#[Group('product-contract')]
class SlotConflictResponseContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'booking.opens_at' => '09:00',
            'booking.closes_at' => '17:00',
            'booking.open_weekdays' => [1, 2, 3, 4, 5],
        ]);

        Carbon::setTestNow('2026-11-02 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_booking_a_taken_slot_returns_422_with_message_and_starts_at_errors(): void
    {
        Notification::fake();

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        Appointment::factory()->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-02 09:00:00',
            ])
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'That time is no longer available.',
                'errors' => [
                    'starts_at' => ['That time is no longer available.'],
                ],
            ]);

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_rescheduling_onto_a_taken_slot_returns_the_same_422_shape(): void
    {
        Notification::fake();

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        Appointment::factory()->for($service)->create([
            'starts_at' => '2026-11-02 14:00:00',
            'ends_at' => '2026-11-02 15:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", [
                'starts_at' => '2026-11-02 14:00:00',
            ])
            ->assertUnprocessable()
            ->assertJson([
                'message' => 'That time is no longer available.',
                'errors' => [
                    'starts_at' => ['That time is no longer available.'],
                ],
            ]);

        $this->assertTrue($appointment->fresh()->starts_at->equalTo('2026-11-02 11:00:00'));
    }

    public function test_status_conflict_returns_409_with_message_only(): void
    {
        Notification::fake();

        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->cancelled()->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertConflict()
            ->assertExactJson([
                'message' => 'Only a confirmed appointment can be cancelled.',
            ]);
    }
}
