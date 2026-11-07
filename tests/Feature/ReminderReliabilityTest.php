<?php

namespace Tests\Feature;

use App\Actions\CreateAppointment;
use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentReminder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Symfony\Component\Mailer\SentMessage;
use Tests\TestCase;

#[Group('adversarial')]
class ReminderReliabilityTest extends TestCase
{
    // Real commits are essential: RefreshDatabase's wrapping transaction would hide enqueue-after-commit failures.
    use DatabaseMigrations;

    protected function beforeRefreshingDatabase(): void
    {
        if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== ':memory:') {
            $this->markTestSkipped('Fault-injection tests require SQLite :memory: to keep the database isolated.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'queue.default' => 'database',
            'mail.default' => 'array',
            'booking.opens_at' => '09:00',
            'booking.closes_at' => '17:00',
            'booking.open_weekdays' => [1, 2, 3, 4, 5],
        ]);
    }

    public function test_a_failed_enqueue_leaves_the_reminder_retryable(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $appointment = Appointment::factory()->create([
            'starts_at' => '2026-11-02 12:00:00',
            'ends_at' => '2026-11-02 13:00:00',
        ]);
        $queueUnavailable = true;
        Event::listen(JobQueueing::class, function (JobQueueing $event) use (&$queueUnavailable): void {
            if ($queueUnavailable && $event->job instanceof SendQueuedNotifications && $event->job->notification instanceof AppointmentReminder) {
                throw new RuntimeException('Injected queue outage.');
            }
        });

        $failure = null;
        try {
            Artisan::call('appointments:send-reminders');
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $this->assertSame('Injected queue outage.', $failure?->getMessage());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertNull($appointment->refresh()->reminded_at, 'A reminder that never reached the queue must remain eligible for retry.');

        $queueUnavailable = false;
        $this->artisan('appointments:send-reminders')->assertSuccessful();

        $this->assertDatabaseCount('jobs', 1);
        $this->assertNotNull($appointment->refresh()->reminded_at);
    }

    public function test_a_queued_reminder_is_not_delivered_after_the_customer_cancels(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $customer = User::factory()->customer()->create();
        $appointment = Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-02 12:00:00',
            'ends_at' => '2026-11-02 13:00:00',
        ]);
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);

        $this->actingAs($customer, 'sanctum')
            ->postJson("/api/appointments/{$appointment->id}/cancel")
            ->assertOk();
        $this->deliverNextQueuedNotification();

        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);
        $this->assertSame([], $this->sentSubjects(), 'The old reminder must be suppressed; the cancellation mail is still queued.');
    }

    public function test_a_queued_reminder_for_the_old_time_is_suppressed_after_rescheduling_outside_the_window(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 12:00:00',
            'ends_at' => '2026-11-02 13:00:00',
        ]);
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", ['starts_at' => '2026-11-04 12:00:00'])
            ->assertOk();
        $this->deliverNextQueuedNotification();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'starts_at' => '2026-11-04 12:00:00',
            'reminded_at' => null,
        ]);
        $this->assertSame([], $this->sentSubjects(), 'Rescheduling must invalidate the reminder for the previous occurrence.');
    }

    public function test_a_delayed_worker_does_not_deliver_a_reminder_after_the_appointment_has_ended(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $appointment = Appointment::factory()->create([
            'starts_at' => '2026-11-02 12:00:00',
            'ends_at' => '2026-11-02 13:00:00',
        ]);
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 1);

        $this->travelTo(Carbon::parse('2026-11-02 14:00:00'));
        $this->artisan('appointments:complete-past')->assertSuccessful();
        $this->deliverNextQueuedNotification();

        $this->assertSame(AppointmentStatus::Completed, $appointment->refresh()->status);
        $this->assertSame([], $this->sentSubjects(), 'A stale reminder must be discarded after a worker outage.');
    }

    public function test_rescheduling_inside_the_window_invalidates_the_old_reminder_without_suppressing_the_new_one(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 12:00:00',
            'ends_at' => '2026-11-02 13:00:00',
        ]);
        $this->artisan('appointments:send-reminders')->assertSuccessful();

        $this->actingAs($customer, 'sanctum')
            ->patchJson("/api/appointments/{$appointment->id}", ['starts_at' => '2026-11-02 15:00:00'])
            ->assertOk();
        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->assertDatabaseCount('jobs', 3);
        $this->deliverNextQueuedNotification();
        $this->deliverNextQueuedNotification();
        $this->deliverNextQueuedNotification();

        $this->assertDatabaseHas('appointments', [
            'id' => $appointment->id,
            'starts_at' => '2026-11-02 15:00:00',
            'ends_at' => '2026-11-02 16:00:00',
        ]);
        $this->assertSame(['Appointment rescheduled', 'Appointment reminder'], $this->sentSubjects());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_a_successful_reminder_reaches_the_customer_through_the_real_database_queue(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $customer = User::factory()->customer()->create(['email' => 'reminder-recipient@example.com']);
        Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-02 12:00:00',
            'ends_at' => '2026-11-02 13:00:00',
        ]);

        $this->artisan('appointments:send-reminders')->assertSuccessful();
        $this->deliverNextQueuedNotification();

        $this->assertSame(['Appointment reminder'], $this->sentSubjects());
        $message = Mail::mailer()->getSymfonyTransport()->messages()->sole()->getOriginalMessage();
        $this->assertSame('reminder-recipient@example.com', $message->getTo()[0]->getAddress());
        $this->assertStringContainsString('2026-11-02 12:00', $message->getHtmlBody());
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_two_interleaved_reminder_runs_enqueue_only_one_mail_for_the_same_appointment(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $appointment = Appointment::factory()->create([
            'starts_at' => '2026-11-02 12:00:00',
            'ends_at' => '2026-11-02 13:00:00',
        ]);
        $secondRunStarted = false;

        // Yield after the first read: the second run finishes before the first uses its stale record.
        Appointment::retrieved(function (Appointment $retrieved) use ($appointment, &$secondRunStarted): void {
            if (! $secondRunStarted && $retrieved->is($appointment) && $retrieved->reminded_at === null) {
                $secondRunStarted = true;
                Artisan::call('appointments:send-reminders');
            }
        });

        $this->artisan('appointments:send-reminders')->assertSuccessful();

        $this->assertTrue($secondRunStarted, 'The test must actually interleave both command executions.');
        $this->assertNotNull($appointment->refresh()->reminded_at);
        $this->assertDatabaseCount('jobs', 1);
    }

    public function test_an_outer_transaction_rollback_discards_both_the_booking_and_its_queued_mail(): void
    {
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00'));
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $failure = null;

        try {
            DB::transaction(function () use ($customer, $service): void {
                $this->app->make(CreateAppointment::class)->handle($customer, $service->id, Carbon::parse('2026-11-02 12:00:00'));
                $this->assertDatabaseCount('jobs', 0);

                throw new RuntimeException('Injected outer transaction rollback.');
            });
        } catch (RuntimeException $exception) {
            $failure = $exception;
        }

        $this->assertSame('Injected outer transaction rollback.', $failure?->getMessage());
        $this->assertDatabaseCount('appointments', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertSame([], $this->sentSubjects());
    }

    private function deliverNextQueuedNotification(): void
    {
        $job = Queue::connection('database')->pop('default');
        $this->assertNotNull($job, 'The test must execute a real queued job, not silently pass with an empty queue.');

        $job->fire();
        $job->delete();
    }

    /**
     * @return list<string>
     */
    private function sentSubjects(): array
    {
        $transport = Mail::mailer()->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);

        return $transport->messages()
            ->map(fn (SentMessage $message): string => $message->getOriginalMessage()->getSubject())
            ->values()
            ->all();
    }
}
