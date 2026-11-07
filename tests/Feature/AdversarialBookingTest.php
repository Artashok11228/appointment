<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use App\Notifications\AppointmentRescheduled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('adversarial')]
class AdversarialBookingTest extends TestCase
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
    }

    #[DataProvider('overlappingIntervals')]
    public function test_returns_422_for_every_shape_of_overlap_without_writing_or_notifying(string $existingStart, string $existingEnd): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $existing = Appointment::factory()->for($service)->create([
            'starts_at' => $existingStart,
            'ends_at' => $existingEnd,
        ]);
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-02 10:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('starts_at');

        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseHas('appointments', [
            'id' => $existing->id,
            'starts_at' => $existingStart,
            'ends_at' => $existingEnd,
            'status' => AppointmentStatus::Confirmed->value,
        ]);
        Notification::assertNothingSent();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function overlappingIntervals(): array
    {
        return [
            'overlap at the start' => ['2026-11-02 09:30:00', '2026-11-02 10:30:00'],
            'overlap at the end' => ['2026-11-02 10:30:00', '2026-11-02 11:30:00'],
            'existing appointment contains the requested slot' => ['2026-11-02 09:30:00', '2026-11-02 11:30:00'],
            'requested slot contains the existing appointment' => ['2026-11-02 10:15:00', '2026-11-02 10:45:00'],
        ];
    }

    #[DataProvider('adjacentIntervals')]
    public function test_touching_intervals_return_201_and_do_not_count_as_overlaps(string $existingStart, string $existingEnd): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        Appointment::factory()->for($service)->create([
            'starts_at' => $existingStart,
            'ends_at' => $existingEnd,
        ]);
        Notification::fake([AppointmentBooked::class]);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-02 10:00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.starts_at', '2026-11-02T10:00:00+00:00')
            ->assertJsonPath('data.ends_at', '2026-11-02T11:00:00+00:00');

        $this->assertDatabaseCount('appointments', 2);
        $this->assertDatabaseHas('appointments', [
            'user_id' => $customer->id,
            'service_id' => $service->id,
            'starts_at' => '2026-11-02 10:00:00',
            'ends_at' => '2026-11-02 11:00:00',
        ]);
        Notification::assertSentToTimes($customer, AppointmentBooked::class, 1);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function adjacentIntervals(): array
    {
        return [
            'existing appointment ends exactly at the new start' => ['2026-11-02 09:00:00', '2026-11-02 10:00:00'],
            'existing appointment starts exactly at the new end' => ['2026-11-02 11:00:00', '2026-11-02 12:00:00'],
        ];
    }

    public function test_booking_ignores_forged_ownership_status_end_time_and_reminder_fields(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $customer = User::factory()->customer()->create();
        $victim = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        Notification::fake([AppointmentBooked::class]);

        $response = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-02 10:00:00',
                'user_id' => $victim->id,
                'status' => AppointmentStatus::Completed->value,
                'ends_at' => '2026-11-02 10:01:00',
                'reminded_at' => '2026-11-02 08:00:00',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user_id', $customer->id)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.ends_at', '2026-11-02T11:00:00+00:00');

        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseHas('appointments', [
            'id' => $response->json('data.id'),
            'user_id' => $customer->id,
            'service_id' => $service->id,
            'status' => AppointmentStatus::Confirmed->value,
            'ends_at' => '2026-11-02 11:00:00',
            'reminded_at' => null,
        ]);
        Notification::assertSentTo($customer, AppointmentBooked::class, fn (AppointmentBooked $notification): bool => $notification->appointment->user_id === $customer->id);
    }

    public function test_rescheduling_ignores_attempts_to_transfer_ownership_or_switch_services(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $customer = User::factory()->customer()->create();
        $victim = User::factory()->customer()->create();
        $originalService = Service::factory()->create(['duration_minutes' => 60]);
        $otherService = Service::factory()->create(['duration_minutes' => 15]);
        $appointment = Appointment::factory()->for($customer)->for($originalService)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
        ]);
        Notification::fake([AppointmentRescheduled::class]);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", [
                'starts_at' => '2026-11-02 13:00:00',
                'user_id' => $victim->id,
                'service_id' => $otherService->id,
                'status' => AppointmentStatus::Cancelled->value,
                'ends_at' => '2026-11-02 13:15:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.user_id', $customer->id)
            ->assertJsonPath('data.service_id', $originalService->id)
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.ends_at', '2026-11-02T14:00:00+00:00');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'user_id' => $customer->id,
            'service_id' => $originalService->id,
            'status' => AppointmentStatus::Confirmed->value,
            'starts_at' => '2026-11-02 13:00:00',
            'ends_at' => '2026-11-02 14:00:00',
        ]);
        Notification::assertSentToTimes($customer, AppointmentRescheduled::class, 1);
    }

    #[DataProvider('closingBoundaries')]
    public function test_a_service_that_cannot_finish_before_closing_returns_422(int $durationMinutes, string $startsAt): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => $durationMinutes]);
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => $startsAt,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('starts_at');

        $this->assertDatabaseCount('appointments', 0);
        Notification::assertNothingSent();
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function closingBoundaries(): array
    {
        return [
            'on grid but ends fifteen minutes after closing' => [45, '2026-11-02 16:30:00'],
            'duration exceeds the whole working day by one minute' => [481, '2026-11-02 09:00:00'],
            'starts exactly at closing' => [60, '2026-11-02 17:00:00'],
        ];
    }
}
