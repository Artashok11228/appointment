<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_filters_keep_a_customer_inside_their_own_appointments(): void
    {
        $customer = User::factory()->customer()->create();
        $other = User::factory()->customer()->create();
        $haircut = Service::factory()->create();
        $shave = Service::factory()->create();

        $match = Appointment::factory()->for($customer)->for($haircut)->create([
            'starts_at' => '2026-11-03 15:00:00',
            'ends_at' => '2026-11-03 16:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);
        Appointment::factory()->for($customer)->for($haircut)->cancelled()->create([
            'starts_at' => '2026-11-03 10:00:00',
            'ends_at' => '2026-11-03 11:00:00',
        ]);
        Appointment::factory()->for($customer)->for($shave)->create([
            'starts_at' => '2026-11-04 09:00:00',
            'ends_at' => '2026-11-04 10:00:00',
        ]);
        Appointment::factory()->for($other)->for($haircut)->create([
            'starts_at' => '2026-11-03 15:00:00',
            'ends_at' => '2026-11-03 16:00:00',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/appointments?'.http_build_query([
                'status' => AppointmentStatus::Confirmed->value,
                'service_id' => $haircut->id,
                'from' => '2026-11-03',
                'to' => '2026-11-03',
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_an_admin_status_filter_includes_every_customer(): void
    {
        $admin = User::factory()->admin()->create();
        $cancelled = Appointment::factory()->cancelled()->create();
        Appointment::factory()->create();

        $this->actingAs($admin, 'sanctum')
            ->getJson('/api/appointments?status='.AppointmentStatus::Cancelled->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $cancelled->id);
    }

    public function test_from_and_to_cover_whole_days_and_can_be_used_alone(): void
    {
        $customer = User::factory()->customer()->create();
        $late = Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-03 23:30:00',
            'ends_at' => '2026-11-04 00:30:00',
        ]);
        $nextMorning = Appointment::factory()->for($customer)->create([
            'starts_at' => '2026-11-04 00:30:00',
            'ends_at' => '2026-11-04 01:30:00',
        ]);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/appointments?from=2026-11-03&to=2026-11-03')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $late->id);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/appointments?from=2026-11-04')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $nextMorning->id);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/appointments?to=2026-11-03')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $late->id);
    }

    public function test_bad_filters_are_rejected(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/appointments?status=nope&service_id=9999&from=Monday&to=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'service_id', 'from']);
    }

    public function test_a_reversed_date_range_is_rejected(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/appointments?from=2026-11-05&to=2026-11-01')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    }

    public function test_a_date_range_ending_on_the_same_day_as_it_starts_is_accepted(): void
    {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/appointments?from=2026-11-03&to=2026-11-03')
            ->assertOk();
    }

    public function test_the_list_is_split_into_pages_of_fifteen(): void
    {
        $customer = User::factory()->customer()->create();

        foreach (range(0, 15) as $offset) {
            $starts = now()->addDays($offset)->startOfHour();

            Appointment::factory()->for($customer)->create([
                'starts_at' => $starts,
                'ends_at' => $starts->copy()->addHour(),
            ]);
        }

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/appointments?status=confirmed')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 16)
            ->assertJsonPath('meta.current_page', 1);

        $this->actingAs($customer, 'sanctum')
            ->getJson('/api/appointments?status=confirmed&page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.current_page', 2);
    }
}
