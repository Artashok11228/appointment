<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use App\Notifications\AppointmentCancelled;
use App\Notifications\AppointmentRescheduled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Client-side chaos: lost responses, blind retries, stale screens, and confused payloads.
 */
#[Group('adversarial')]
class ClientRetryAndConfusionTest extends TestCase
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
    }

    public function test_a_lost_booking_response_retry_does_not_create_a_second_appointment_or_mail(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00'));
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $payload = [
            'service_id' => $service->id,
            'starts_at' => '2026-11-02 11:00:00',
        ];
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', $payload)
            ->assertCreated();

        // Client never saw 201 and taps Book again.
        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('starts_at');

        $this->assertDatabaseCount('appointments', 1);
        Notification::assertSentToTimes($customer, AppointmentBooked::class, 1);
    }

    public function test_a_double_cancel_retry_keeps_one_cancellation_and_one_mail(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00'));
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
        ]);
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertConflict()
            ->assertJsonPath('message', 'Only a confirmed appointment can be cancelled.');

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);
        Notification::assertSentToTimes($customer, AppointmentCancelled::class, 1);
    }

    public function test_rescheduling_the_same_destination_twice_keeps_a_single_appointment(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00'));
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
        ]);
        $payload = ['starts_at' => '2026-11-02 14:00:00'];
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", $payload)
            ->assertOk();

        // Lost 200; client retries the identical move.
        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", $payload)
            ->assertOk()
            ->assertJsonPath('data.starts_at', '2026-11-02T14:00:00+00:00');

        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'starts_at' => '2026-11-02 14:00:00',
            'ends_at' => '2026-11-02 15:00:00',
            'status' => AppointmentStatus::Confirmed->value,
        ]);
        Notification::assertSentToTimes($customer, AppointmentRescheduled::class, 2);
    }

    public function test_a_stale_reschedule_after_cancel_returns_409_without_mail(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00'));
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
        ]);
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", [
                'starts_at' => '2026-11-02 14:00:00',
            ])
            ->assertConflict()
            ->assertJsonPath('message', 'Only a confirmed appointment can be rescheduled.');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'status' => AppointmentStatus::Cancelled->value,
            'starts_at' => '2026-11-02 11:00:00',
        ]);
        Notification::assertSentToTimes($customer, AppointmentCancelled::class, 1);
        Notification::assertNotSentTo($customer, AppointmentRescheduled::class);
    }

    public function test_a_customer_cancel_after_an_admin_already_cancelled_returns_409(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00'));
        $customer = User::factory()->customer()->create();
        $admin = User::factory()->admin()->create();
        $appointment = Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
        ]);
        Notification::fake();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk();

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertConflict();

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);
        Notification::assertSentToTimes($customer, AppointmentCancelled::class, 1);
    }

    public function test_waiting_until_the_lock_window_then_cancelling_returns_409_without_mail(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00'));
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
        ]);
        Notification::fake();

        // Customer opens the screen early, then comes back after the lock starts.
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00'));

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertConflict()
            ->assertJsonPath('message', 'This appointment can no longer be cancelled.');

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->refresh()->status);
        Notification::assertNothingSent();
    }

    public function test_a_stale_availability_screen_cannot_steal_a_slot_another_customer_just_took(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00'));
        $first = User::factory()->customer()->create();
        $second = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        Notification::fake();

        $this->actingAs($first, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-02 11:00:00',
            ])
            ->assertCreated();

        $this->actingAs($second, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-02 11:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('starts_at');

        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseHas('appointments', [
            'user_id' => $first->id,
            'starts_at' => '2026-11-02 11:00:00',
            'status' => AppointmentStatus::Confirmed->value,
        ]);
        Notification::assertSentToTimes($first, AppointmentBooked::class, 1);
        Notification::assertNotSentTo($second, AppointmentBooked::class);
    }

    public function test_a_slot_that_was_free_on_screen_but_has_now_started_cannot_be_booked(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        Notification::fake();

        $this->travelTo(Carbon::parse('2026-11-02 09:05:00'));

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-02 09:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('starts_at');

        $this->assertDatabaseCount('appointments', 0);
        Notification::assertNothingSent();
    }

    public function test_a_revoked_token_cannot_finish_a_cancel_the_user_started_before_logout(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00'));
        $customer = User::factory()->customer()->create([
            'email' => 'retry@example.com',
            'password' => 'password',
        ]);
        $appointment = Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
        ]);
        Notification::fake();

        $token = $this->postJson('/api/login', [
            'email' => 'retry@example.com',
            'password' => 'password',
        ])->json('token');

        $this->withToken($token)
            ->postJson('/api/logout')
            ->assertNoContent();

        $this->app['auth']->forgetGuards();

        $this->withToken($token)
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertUnauthorized();

        $this->assertSame(AppointmentStatus::Confirmed, $appointment->refresh()->status);
        Notification::assertNothingSent();
    }

    public function test_a_typo_appointment_id_returns_404_without_side_effects(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00'));
        $customer = User::factory()->customer()->create();
        Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
        ]);
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments/999999/cancel')
            ->assertNotFound();

        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseHas('appointments', [
            'status' => AppointmentStatus::Confirmed->value,
        ]);
        Notification::assertNothingSent();
    }

    public function test_confused_booking_and_reschedule_payloads_are_rejected_without_writes(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00'));
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
        ]);
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id', 'starts_at']);

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => 'not-an-id',
                'starts_at' => 'tomorrow morning',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['service_id', 'starts_at']);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['starts_at']);

        $this->assertDatabaseCount('appointments', 1);
        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'starts_at' => '2026-11-02 11:00:00',
            'status' => AppointmentStatus::Confirmed->value,
        ]);
        Notification::assertNothingSent();
    }

    public function test_rescheduling_onto_a_slot_taken_after_the_customer_opened_the_form_is_rejected(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 06:00:00'));
        $customer = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 11:00:00',
            'ends_at' => '2026-11-02 12:00:00',
        ]);
        Appointment::factory()->for($other)->for($service)->create([
            'starts_at' => '2026-11-02 14:00:00',
            'ends_at' => '2026-11-02 15:00:00',
        ]);
        Notification::fake();

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", [
                'starts_at' => '2026-11-02 14:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('starts_at');

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'starts_at' => '2026-11-02 11:00:00',
            'status' => AppointmentStatus::Confirmed->value,
        ]);
        Notification::assertNothingSent();
    }
}
