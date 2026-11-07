<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CompletePastAppointmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-02 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_confirmed_appointments_that_have_ended_become_completed(): void
    {
        $ended = Appointment::factory()->create([
            'starts_at' => '2026-11-02 10:00:00',
            'ends_at' => '2026-11-02 11:00:00',
        ]);
        $inProgress = Appointment::factory()->create([
            'starts_at' => '2026-11-02 11:30:00',
            'ends_at' => '2026-11-02 12:30:00',
        ]);
        $upcoming = Appointment::factory()->create([
            'starts_at' => '2026-11-03 10:00:00',
            'ends_at' => '2026-11-03 11:00:00',
        ]);

        $this->artisan('appointments:complete-past')
            ->expectsOutput('Completed 1 appointment(s).')
            ->assertSuccessful();

        $this->assertSame(AppointmentStatus::Completed, $ended->refresh()->status);
        $this->assertSame(AppointmentStatus::Confirmed, $inProgress->refresh()->status);
        $this->assertSame(AppointmentStatus::Confirmed, $upcoming->refresh()->status);
    }

    public function test_cancelled_appointments_in_the_past_stay_cancelled(): void
    {
        $cancelled = Appointment::factory()->cancelled()->create([
            'starts_at' => '2026-11-02 10:00:00',
            'ends_at' => '2026-11-02 11:00:00',
        ]);

        $this->artisan('appointments:complete-past')->assertSuccessful();

        $this->assertSame(AppointmentStatus::Cancelled, $cancelled->refresh()->status);
    }

    public function test_the_command_runs_hourly(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'appointments:complete-past'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
    }
}
