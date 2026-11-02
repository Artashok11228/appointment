<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_guest_cannot_list_services(): void
    {
        $this->getJson('/api/services')->assertUnauthorized();
    }

    public function test_a_customer_can_list_and_view_services(): void
    {
        $customer = User::factory()->customer()->create();
        Service::factory()->create(['name' => 'Haircut', 'duration_minutes' => 45, 'price' => 80]);
        Service::factory()->create(['name' => 'Beard trim', 'duration_minutes' => 20, 'price' => 30]);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/services')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Beard trim')
            ->assertJsonPath('data.1.name', 'Haircut')
            ->assertJsonPath('data.0.price', '30.00');

        $service = Service::query()->where('name', 'Haircut')->firstOrFail();

        $this->actingAs($customer, 'sanctum')
            ->getJson("/api/services/{$service->id}")
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 45)
            ->assertJsonPath('data.price', '80.00');
    }

    public function test_a_customer_cannot_change_services(): void
    {
        $customer = User::factory()->customer()->create();
        $service = Service::factory()->create();
        $payload = [
            'name' => 'Color',
            'duration_minutes' => 90,
            'price' => 150,
        ];

        $this->actingAs($customer, 'sanctum')
            ->postJson('/api/services', $payload)
            ->assertForbidden();

        $this->actingAs($customer, 'sanctum')
            ->putJson("/api/services/{$service->id}", $payload)
            ->assertForbidden();

        $this->actingAs($customer, 'sanctum')
            ->deleteJson("/api/services/{$service->id}")
            ->assertForbidden();

        $this->assertModelExists($service);
    }

    public function test_an_admin_can_create_update_and_delete_a_service(): void
    {
        $admin = User::factory()->admin()->create();

        $created = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/services', [
                'name' => 'Haircut',
                'duration_minutes' => 45,
                'price' => 80,
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Haircut')
            ->assertJsonPath('data.price', '80.00');

        $serviceId = $created->json('data.id');

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/services/{$serviceId}", [
                'name' => 'Haircut',
                'duration_minutes' => 60,
                'price' => 95.5,
            ])
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 60)
            ->assertJsonPath('data.price', '95.50');

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/services/{$serviceId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('services', ['id' => $serviceId]);
    }

    public function test_creating_a_service_checks_the_fields(): void
    {
        $admin = User::factory()->admin()->create();
        Service::factory()->create(['name' => 'Haircut']);

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/services', [
                'name' => 'Haircut',
                'duration_minutes' => 0,
                'price' => -1,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'duration_minutes', 'price']);
    }

    public function test_an_admin_can_keep_the_same_service_name(): void
    {
        $admin = User::factory()->admin()->create();
        $service = Service::factory()->create([
            'name' => 'Haircut',
            'duration_minutes' => 30,
            'price' => 40,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/services/{$service->id}", [
                'name' => 'Haircut',
                'duration_minutes' => 40,
                'price' => 50,
            ])
            ->assertOk()
            ->assertJsonPath('data.duration_minutes', 40);
    }

    public function test_a_service_with_a_future_confirmed_appointment_cannot_be_deleted(): void
    {
        Carbon::setTestNow('2026-11-02 08:00:00');
        $admin = User::factory()->admin()->create();
        $service = Service::factory()->create();
        $appointment = Appointment::factory()->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/services/{$service->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('service')
            ->assertJsonPath('errors.service.0', 'This service has upcoming confirmed appointments.');

        $this->assertModelExists($service);
        $this->assertModelExists($appointment);
    }

    public function test_a_service_can_be_deleted_when_appointments_are_past_or_not_confirmed(): void
    {
        Carbon::setTestNow('2026-11-02 12:00:00');
        $admin = User::factory()->admin()->create();
        $service = Service::factory()->create();
        $past = Appointment::factory()->for($service)->create([
            'starts_at' => '2026-11-02 09:00:00',
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);
        $cancelled = Appointment::factory()->for($service)->cancelled()->create([
            'starts_at' => '2026-11-03 09:00:00',
            'ends_at' => '2026-11-03 10:00:00',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/services/{$service->id}")
            ->assertNoContent();

        $this->assertModelMissing($service);
        $this->assertModelMissing($past);
        $this->assertModelMissing($cancelled);
    }

    public function test_a_missing_service_returns_not_found(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/services/999')
            ->assertNotFound();
    }
}
