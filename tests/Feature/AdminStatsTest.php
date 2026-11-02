<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminStatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-15 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_guest_and_a_customer_cannot_read_admin_stats(): void
    {
        $customer = User::factory()->customer()->create();

        $this->getJson('/api/admin/stats')->assertUnauthorized();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/admin/stats')
            ->assertForbidden();
    }

    public function test_an_admin_sees_status_counts_top_services_and_cancellation_rate(): void
    {
        $admin = User::factory()->admin()->create();
        $haircut = Service::factory()->create(['name' => 'Haircut']);
        $shave = Service::factory()->create(['name' => 'Shave']);

        Appointment::factory()->for($haircut)->count(3)->create([
            'status' => AppointmentStatus::Confirmed,
        ]);
        Appointment::factory()->for($haircut)->cancelled()->create();
        Appointment::factory()->for($shave)->completed()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonPath('data.by_status.confirmed', 3)
            ->assertJsonPath('data.by_status.cancelled', 1)
            ->assertJsonPath('data.by_status.completed', 1)
            ->assertJsonPath('data.cancellation_rate', 0.2)
            ->assertJsonPath('data.top_services.0.name', 'Haircut')
            ->assertJsonPath('data.top_services.0.total', 4)
            ->assertJsonPath('data.top_services.1.name', 'Shave')
            ->assertJsonPath('data.top_services.1.total', 1);
    }

    public function test_bookings_per_day_covers_the_configured_window(): void
    {
        $admin = User::factory()->admin()->create();
        $service = Service::factory()->create();

        Appointment::factory()->for($service)->create([
            'created_at' => '2026-11-15 09:00:00',
            'updated_at' => '2026-11-15 09:00:00',
        ]);
        Appointment::factory()->for($service)->create([
            'created_at' => '2026-11-10 09:00:00',
            'updated_at' => '2026-11-10 09:00:00',
        ]);
        Appointment::factory()->for($service)->create([
            'created_at' => '2026-10-01 09:00:00',
            'updated_at' => '2026-10-01 09:00:00',
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/stats')
            ->assertOk();

        $days = $response->json('data.bookings_per_day');

        $this->assertCount(30, $days);
        $this->assertSame('2026-10-17', $days[0]['date']);
        $this->assertSame('2026-11-15', $days[29]['date']);
        $this->assertSame(1, collect($days)->firstWhere('date', '2026-11-15')['total']);
        $this->assertSame(1, collect($days)->firstWhere('date', '2026-11-10')['total']);
        $this->assertSame(0, collect($days)->firstWhere('date', '2026-11-01')['total']);
    }

    public function test_empty_stats_return_zero_rates_and_empty_top_services(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonPath('data.by_status.confirmed', 0)
            ->assertJsonPath('data.by_status.cancelled', 0)
            ->assertJsonPath('data.by_status.completed', 0)
            ->assertJsonPath('data.cancellation_rate', 0)
            ->assertJsonCount(0, 'data.top_services')
            ->assertJsonCount(30, 'data.bookings_per_day');
    }
}
