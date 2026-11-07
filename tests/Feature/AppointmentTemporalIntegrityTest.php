<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Customers cannot change ended appointments while they are still confirmed,
 * even before the hourly completion command updates their status.
 */
#[Group('adversarial')]
#[Group('product-contract')]
class AppointmentTemporalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('endedAppointmentTimes')]
    public function test_returns_409_for_cancelling_an_ended_appointment_before_the_completion_command_runs(string $now): void
    {
        $this->travelTo(Carbon::parse($now));
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
        ]);
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertConflict();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => AppointmentStatus::Confirmed->value,
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
        ]);
        Notification::assertNothingSent();
    }

    #[DataProvider('endedAppointmentTimes')]
    public function test_returns_409_for_moving_an_ended_appointment_into_the_future(string $now): void
    {
        $this->travelTo(Carbon::parse($now));
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
        ]);
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", ['starts_at' => '2026-11-02 12:00:00'])
            ->assertConflict();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => AppointmentStatus::Confirmed->value,
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
        ]);
        Notification::assertNothingSent();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endedAppointmentTimes(): array
    {
        return [
            'exactly at the end' => ['2026-11-02 10:00:00'],
            'before the next hourly completion run' => ['2026-11-02 10:15:00'],
        ];
    }
}
