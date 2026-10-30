<?php

namespace Tests\Feature;

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_demo_seeder_creates_an_admin_a_customer_and_three_services(): void
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
    }
}
