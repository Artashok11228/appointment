<?php

namespace Database\Seeders;

use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->admin()->create([
            'name' => 'Studio Admin',
            'email' => 'admin@example.com',
        ]);

        User::factory()->create([
            'name' => 'Sample Customer',
            'email' => 'customer@example.com',
        ]);

        foreach ([
            ['name' => 'Haircut', 'duration_minutes' => 45, 'price' => 80],
            ['name' => 'Beard trim', 'duration_minutes' => 30, 'price' => 40],
            ['name' => 'Haircut and beard', 'duration_minutes' => 75, 'price' => 110],
        ] as $service) {
            Service::factory()->create($service);
        }
    }
}
