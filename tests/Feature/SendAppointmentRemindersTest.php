<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Notifications\AppointmentReminder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SendAppointmentRemindersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-02 08:00:00');
        Notification::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_reminder_is_queued_once(): void
    {
        $appointment = Appointment::factory()->create([
            'starts_at' => '2026-11-02 20:00:00',
            'ends_at' => '2026-11-02 21:00:00',
        ]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Notification::assertSentToTimes($appointment->user, AppointmentReminder::class, 1);
        Notification::assertSentTo(
            $appointment->user,
            AppointmentReminder::class,
            fn (AppointmentReminder $notification): bool => $notification instanceof ShouldQueue
                && $notification instanceof ShouldQueueAfterCommit,
        );
        $this->assertNotNull($appointment->refresh()->reminded_at);
    }

    public function test_cancelled_appointments_are_ignored(): void
    {
        $appointment = Appointment::factory()->cancelled()->create([
            'starts_at' => '2026-11-02 12:00:00',
            'ends_at' => '2026-11-02 13:00:00',
        ]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Notification::assertNotSentTo($appointment->user, AppointmentReminder::class);
        $this->assertNull($appointment->refresh()->reminded_at);
    }

    public function test_appointments_beyond_the_reminder_window_are_ignored(): void
    {
        $starts = now()->addHours((int) config('booking.reminder_hours'))->addMinute();
        $appointment = Appointment::factory()->create([
            'starts_at' => $starts,
            'ends_at' => $starts->copy()->addHour(),
        ]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        Notification::assertNotSentTo($appointment->user, AppointmentReminder::class);
        $this->assertNull($appointment->refresh()->reminded_at);
    }

    public function test_the_command_runs_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'appointments:send-reminders'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }
}
