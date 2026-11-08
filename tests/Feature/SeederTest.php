<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Enums\Role;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_seeder_creates_an_admin_a_customer_three_services_and_sample_appointments(): void
    {
        $this->seed();

        $this->assertDatabaseHas('users', [
            'email' => 'admin@example.com',
            'role' => Role::Admin->value,
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'customer@example.com',
            'role' => Role::Customer->value,
        ]);
        $this->assertDatabaseHas('services', [
            'name' => 'Haircut',
            'duration_minutes' => 45,
        ]);
        $this->assertDatabaseCount('services', 3);
        $this->assertDatabaseCount('appointments', 4);

        $customer = User::query()->where('email', 'customer@example.com')->firstOrFail();
        $haircut = Service::query()->where('name', 'Haircut')->firstOrFail();

        $confirmed = Appointment::query()
            ->where('user_id', $customer->id)
            ->where('service_id', $haircut->id)
            ->where('status', AppointmentStatus::Confirmed)
            ->firstOrFail();

        $this->assertSame(
            $haircut->duration_minutes,
            (int) $confirmed->starts_at->diffInMinutes($confirmed->ends_at),
        );
    }

    public function test_the_demo_seeder_can_run_twice_without_duplicate_key_errors(): void
    {
        $this->seed();
        $this->seed();

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('services', 3);
        $this->assertDatabaseCount('appointments', 4);
    }

    public function test_the_demo_seeder_is_a_no_op_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('services', 0);
        $this->assertDatabaseCount('appointments', 0);
    }
}
