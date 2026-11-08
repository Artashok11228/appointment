<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            return;
        }

        User::query()->where('email', 'admin@example.com')->first()
            ?? User::factory()->admin()->create([
                'name' => 'Studio Admin',
                'email' => 'admin@example.com',
            ]);

        $customer = User::query()->where('email', 'customer@example.com')->first()
            ?? User::factory()->customer()->create([
                'name' => 'Sample Customer',
                'email' => 'customer@example.com',
            ]);

        $services = [];
        foreach ([
            ['name' => 'Haircut', 'duration_minutes' => 45, 'price' => 80],
            ['name' => 'Beard trim', 'duration_minutes' => 30, 'price' => 40],
            ['name' => 'Haircut and beard', 'duration_minutes' => 75, 'price' => 110],
        ] as $service) {
            $services[$service['name']] = Service::query()->updateOrCreate(
                ['name' => $service['name']],
                [
                    'duration_minutes' => $service['duration_minutes'],
                    'price' => $service['price'],
                ],
            );
        }

        $this->seedDemoAppointments($customer, $services);
    }

    /**
     * @param  array<string, Service>  $services
     */
    private function seedDemoAppointments(User $customer, array $services): void
    {
        Appointment::query()->where('user_id', $customer->id)->delete();

        $week = now()->copy()->startOfWeek(Carbon::MONDAY)->addWeek();

        $this->createAppointment(
            $customer,
            $services['Haircut'],
            $week->copy()->setTime(10, 0),
            AppointmentStatus::Confirmed,
        );

        $this->createAppointment(
            $customer,
            $services['Beard trim'],
            $week->copy()->addDay()->setTime(11, 0),
            AppointmentStatus::Confirmed,
        );

        $this->createAppointment(
            $customer,
            $services['Haircut'],
            $week->copy()->addDays(2)->setTime(14, 0),
            AppointmentStatus::Cancelled,
        );

        $this->createAppointment(
            $customer,
            $services['Haircut and beard'],
            $week->copy()->subWeek()->setTime(9, 0),
            AppointmentStatus::Completed,
        );
    }

    private function createAppointment(
        User $customer,
        Service $service,
        Carbon $startsAt,
        AppointmentStatus $status,
    ): void {
        Appointment::query()->create([
            'user_id' => $customer->id,
            'service_id' => $service->id,
            'starts_at' => $startsAt,
            'ends_at' => $startsAt->copy()->addMinutes($service->duration_minutes),
            'status' => $status,
            'reminded_at' => null,
        ]);
    }
}
