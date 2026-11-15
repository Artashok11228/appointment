<?php

namespace Tests\Feature;

use App\Actions\CreateAppointment;
use App\Actions\ListAvailableSlots;
use App\Actions\RescheduleAppointment;
use App\Enums\AppointmentStatus;
use App\Exceptions\AppointmentUnavailable;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ActiveAppointmentSlotUniquenessTest extends TestCase
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

        Carbon::setTestNow('2026-11-02 06:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_active_starts_at_is_unique_per_service_for_non_cancelled_appointments(): void
    {
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $startsAt = '2026-11-02 09:00:00';
        $endsAt = '2026-11-02 10:00:00';

        Appointment::factory()->for($service)->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->expectException(QueryException::class);

        Appointment::factory()->for($service)->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => AppointmentStatus::Confirmed,
        ]);
    }

    public function test_cancelled_slot_can_be_booked_again_at_the_same_starts_at(): void
    {
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $startsAt = '2026-11-02 09:00:00';
        $endsAt = '2026-11-02 10:00:00';

        Appointment::factory()->for($service)->cancelled()->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $replacement = Appointment::factory()->for($service)->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->assertSame(AppointmentStatus::Confirmed, $replacement->status);
        $this->assertTrue($replacement->starts_at->equalTo($startsAt));
        $this->assertSame(2, Appointment::query()->where('service_id', $service->id)->count());
    }

    public function test_reschedule_updates_starts_at_without_breaking_the_unique_constraint(): void
    {
        Notification::fake();

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $updated = app(RescheduleAppointment::class)->handle(
            $customer,
            $appointment,
            Carbon::parse('2026-11-02 11:00:00'),
        );

        $this->assertTrue($updated->starts_at->equalTo('2026-11-02 11:00:00'));
        $this->assertTrue($updated->ends_at->equalTo('2026-11-02 12:00:00'));

        $rebookedOriginalSlot = Appointment::factory()->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->assertTrue($rebookedOriginalSlot->starts_at->equalTo('2026-11-02 09:00:00'));
    }

    public function test_completed_appointments_still_block_the_same_starts_at(): void
    {
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $startsAt = '2026-11-02 09:00:00';
        $endsAt = '2026-11-02 10:00:00';

        Appointment::factory()->for($service)->completed()->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);

        $this->expectException(QueryException::class);

        Appointment::factory()->for($service)->create([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => AppointmentStatus::Confirmed,
        ]);
    }

    public function test_appointments_have_a_unique_index_on_service_and_active_starts_at(): void
    {
        $index = collect(Schema::getIndexes('appointments'))
            ->first(fn (array $index): bool => $index['columns'] === ['service_id', 'active_starts_at']);

        $this->assertNotNull($index);
        $this->assertTrue($index['unique']);
    }

    public function test_create_maps_unique_violation_to_appointment_unavailable(): void
    {
        Notification::fake();

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $startsAt = Carbon::parse('2026-11-02 09:00:00');

        Appointment::factory()->for($service)->create([
            'starts_at' => $startsAt,
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->mock(ListAvailableSlots::class, function ($mock): void {
            $mock->shouldReceive('isFree')->once()->andReturn(true);
        });

        try {
            app(CreateAppointment::class)->handle($customer, $service->id, $startsAt);
            $this->fail('Expected AppointmentUnavailable when the unique index rejects the slot.');
        } catch (AppointmentUnavailable) {
        }

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_book_endpoint_returns_422_when_unique_constraint_blocks_the_slot(): void
    {
        Notification::fake();

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);

        Appointment::factory()->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->mock(ListAvailableSlots::class, function ($mock): void {
            $mock->shouldReceive('isFree')->once()->andReturn(true);
        });

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/appointments', [
                'service_id' => $service->id,
                'starts_at' => '2026-11-02 09:00:00',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('starts_at');

        $this->assertDatabaseCount('appointments', 1);
    }

    public function test_reschedule_maps_unique_violation_to_appointment_unavailable(): void
    {
        Notification::fake();

        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $takenStartsAt = Carbon::parse('2026-11-02 11:00:00');

        Appointment::factory()->for($service)->create([
            'starts_at' => $takenStartsAt,
            'ends_at' => '2026-11-02 12:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $appointment = Appointment::factory()->for($customer)->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->mock(ListAvailableSlots::class, function ($mock): void {
            $mock->shouldReceive('isFree')->once()->andReturn(true);
        });

        try {
            app(RescheduleAppointment::class)->handle($customer, $appointment, $takenStartsAt);
            $this->fail('Expected AppointmentUnavailable when the unique index rejects the slot.');
        } catch (AppointmentUnavailable) {
        }

        $this->assertTrue($appointment->fresh()->starts_at->equalTo('2026-11-02 09:00:00'));
    }
}
