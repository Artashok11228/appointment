<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_factories_build_a_customer_a_service_and_a_confirmed_appointment(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->create([
            'duration_minutes' => 45,
            'price' => 19.5,
        ]);
        $appointment = Appointment::factory()->for($user)->for($service)->create();

        $this->assertSame(Role::Customer, $user->role);
        $this->assertSame(45, $service->duration_minutes);
        $this->assertSame('19.50', $service->price);
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->status);
        $this->assertSame(
            $appointment->starts_at->copy()->addHour()->timestamp,
            $appointment->ends_at->timestamp,
        );
    }

    public function test_factory_states_set_role_and_appointment_status(): void
    {
        $this->assertSame(Role::Admin, User::factory()->admin()->create()->role);
        $this->assertSame(Role::Customer, User::factory()->customer()->create()->role);
        $this->assertSame(
            AppointmentStatus::Cancelled,
            Appointment::factory()->cancelled()->create()->status,
        );
        $this->assertSame(
            AppointmentStatus::Completed,
            Appointment::factory()->completed()->create()->status,
        );
    }

    public function test_a_new_user_defaults_to_customer_and_a_new_appointment_defaults_to_confirmed(): void
    {
        $user = User::query()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'password',
        ]);
        $service = Service::factory()->create();
        $appointment = Appointment::query()->create([
            'user_id' => $user->id,
            'service_id' => $service->id,
            'starts_at' => '2026-11-02 10:00:00',
            'ends_at' => '2026-11-02 11:00:00',
        ]);

        $this->assertSame(Role::Customer, $user->refresh()->role);
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->refresh()->status);
        $this->assertTrue($appointment->starts_at->equalTo('2026-11-02 10:00:00'));
    }

    public function test_appointments_belong_to_a_user_and_a_service(): void
    {
        $user = User::factory()->create();
        $service = Service::factory()->create();
        $appointment = Appointment::factory()->for($user)->for($service)->create();

        $this->assertTrue($user->appointments()->first()->is($appointment));
        $this->assertTrue($service->appointments()->first()->is($appointment));
        $this->assertTrue($appointment->user->is($user));
        $this->assertTrue($appointment->service->is($service));
    }

    public function test_deleting_a_user_or_service_deletes_their_appointments(): void
    {
        $userAppointment = Appointment::factory()->create();
        $serviceAppointment = Appointment::factory()->create();

        $userAppointment->user->delete();
        $serviceAppointment->service->delete();

        $this->assertModelMissing($userAppointment);
        $this->assertModelMissing($serviceAppointment);
    }

    public function test_an_appointment_cannot_point_at_a_missing_user(): void
    {
        $this->expectException(QueryException::class);

        Appointment::factory()->create(['user_id' => 999999]);
    }

    public function test_email_addresses_are_unique(): void
    {
        User::factory()->create(['email' => 'ada@example.com']);

        $this->expectException(QueryException::class);

        User::factory()->create(['email' => 'ada@example.com']);
    }

    public function test_appointments_are_indexed_for_schedule_lookups(): void
    {
        $index = collect(Schema::getIndexes('appointments'))
            ->first(fn (array $index): bool => $index['columns'] === ['service_id', 'starts_at', 'ends_at']);

        $this->assertNotNull($index);
        $this->assertFalse($index['unique']);
    }

    public function test_appointment_foreign_keys_cascade_on_delete(): void
    {
        $foreignKeys = collect(Schema::getForeignKeys('appointments'));

        foreach (['user_id' => 'users', 'service_id' => 'services'] as $column => $table) {
            $foreignKey = $foreignKeys->first(
                fn (array $key): bool => $key['columns'] === [$column] && $key['foreign_table'] === $table,
            );

            $this->assertNotNull($foreignKey);
            $this->assertSame('cascade', strtolower((string) $foreignKey['on_delete']));
        }
    }
}
