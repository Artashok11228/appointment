<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentBooked;
use App\Notifications\AppointmentCancelled;
use App\Notifications\AppointmentRescheduled;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationTest extends TestCase
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
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_booking_cancelling_and_rescheduling_queue_mail_for_the_customer(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['name' => 'Haircut', 'duration_minutes' => 60]);

        $created = $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-02 09:00:00',
            ])
            ->assertCreated();

        $appointment = Appointment::query()->findOrFail($created->json('data.id'));

        Notification::assertSentTo($customer, AppointmentBooked::class);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", [
                'starts_at' => '2026-11-02 10:00:00',
            ])
            ->assertOk();

        Notification::assertSentTo($customer, AppointmentRescheduled::class);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk();

        Notification::assertSentTo($customer, AppointmentCancelled::class);
    }

    public function test_appointment_mail_waits_for_the_database_queue(): void
    {
        $appointment = Appointment::factory()->create();
        $booked = new AppointmentBooked($appointment->load('service'));
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertInstanceOf(ShouldQueue::class, $booked);
        $this->assertInstanceOf(ShouldQueueAfterCommit::class, $booked);
        $this->assertSame(['mail'], $booked->via($appointment->user));
        $mail = $booked->toMail($appointment->user);

        $this->assertSame('Appointment booked', $mail->subject);
        $this->assertStringContainsString($appointment->service->name, $mail->render());
        $this->assertStringContainsString('QUEUE_CONNECTION=database', $example);
        $this->assertStringContainsString('MAIL_MAILER=log', $example);
    }
}
