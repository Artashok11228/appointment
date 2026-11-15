<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Process\InvokedProcess;
use Illuminate\Process\Pool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

#[Group('mysql')]
class ConcurrentBookingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Requires MySQL for real row locks.');
        }

        // migrate:fresh wipes the database, so refuse anything that does not look like a test database.
        if (! str_ends_with((string) config('database.connections.mysql.database'), '_test')) {
            $this->markTestSkipped('DB_DATABASE must end in _test to run the concurrency test.');
        }

        // RefreshDatabase wraps one transaction, so two processes must use a real migrated database.
        Artisan::call('migrate:fresh');

        config([
            'booking.opens_at' => '09:00',
            'booking.closes_at' => '11:00',
            'booking.open_weekdays' => [1, 2, 3, 4, 5],
        ]);

        Carbon::setTestNow('2026-11-02 08:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_only_one_of_two_parallel_bookings_for_the_same_slot_succeeds(): void
    {
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $firstCustomer = User::factory()->customer()->create();
        $secondCustomer = User::factory()->customer()->create();
        $startsAt = '2026-11-02 09:00:00';
        $script = $this->bookingScriptPath();

        $results = Process::concurrently(function (Pool $pool) use ($script, $firstCustomer, $secondCustomer, $service, $startsAt): void {
            $env = $this->childEnvironment();

            $pool->path(base_path())->env($env)->timeout(60)->command([
                PHP_BINARY,
                $script,
                (string) $firstCustomer->id,
                (string) $service->id,
                $startsAt,
            ]);

            $pool->path(base_path())->env($env)->timeout(60)->command([
                PHP_BINARY,
                $script,
                (string) $secondCustomer->id,
                (string) $service->id,
                $startsAt,
            ]);
        });

        $exitCodes = collect($results)->map->exitCode()->sort()->values()->all();

        $this->assertSame([0, 2], $exitCodes);
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_second_booking_waits_on_service_lock_then_gets_unavailable(): void
    {
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $firstCustomer = User::factory()->customer()->create();
        $secondCustomer = User::factory()->customer()->create();
        $startsAt = '2026-11-02 09:00:00';
        $script = $this->bookingScriptPath();

        // Hold the same service row lock CreateAppointment takes, so the child must wait.
        DB::beginTransaction();
        Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

        $process = Process::path(base_path())
            ->env($this->childEnvironment())
            ->timeout(60)
            ->start([
                PHP_BINARY,
                $script,
                (string) $secondCustomer->id,
                (string) $service->id,
                $startsAt,
            ]);

        $this->waitUntilChildIsReady($process);

        // If CreateAppointment skipped lockForUpdate, the child would finish here instead of blocking.
        usleep(250_000);
        $this->assertTrue(
            $process->running(),
            'Second booking should still be waiting on the service row lock.',
        );

        Appointment::factory()->for($firstCustomer)->for($service)->create([
            'starts_at' => $startsAt,
            'ends_at' => '2026-11-02 10:00:00',
            'status' => AppointmentStatus::Confirmed,
        ]);
        DB::commit();

        $result = $process->wait();

        $this->assertSame(2, $result->exitCode(), $result->errorOutput());
        $this->assertStringContainsString("taken\n", $result->output());
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_eight_parallel_bookings_for_the_same_slot_create_one_appointment(): void
    {
        $service = Service::factory()->create(['duration_minutes' => 60]);
        $customers = User::factory()->customer()->count(8)->create();
        $startsAt = '2026-11-02 09:00:00';
        $script = $this->bookingScriptPath();

        $results = Process::concurrently(function (Pool $pool) use ($script, $customers, $service, $startsAt): void {
            $env = $this->childEnvironment();

            foreach ($customers as $customer) {
                $pool->path(base_path())->env($env)->timeout(60)->command([
                    PHP_BINARY,
                    $script,
                    (string) $customer->id,
                    (string) $service->id,
                    $startsAt,
                ]);
            }
        });

        $exitCodes = collect($results)->map->exitCode();

        $this->assertSame(1, $exitCodes->filter(fn (int $code): bool => $code === 0)->count());
        $this->assertSame(7, $exitCodes->filter(fn (int $code): bool => $code === 2)->count());
        $this->assertTrue($exitCodes->every(fn (int $code): bool => in_array($code, [0, 2], true)));
        $this->assertSame(1, Appointment::query()->where('status', AppointmentStatus::Confirmed)->count());
    }

    private function waitUntilChildIsReady(InvokedProcess $process): void
    {
        $deadline = microtime(true) + 15;

        while (microtime(true) < $deadline) {
            if (str_contains($process->output(), "ready\n")) {
                return;
            }

            if (! $process->running()) {
                $this->fail(
                    'Child exited before reaching CreateAppointment: '
                    .$process->errorOutput().$process->output()
                );
            }

            usleep(20_000);
        }

        $this->fail('Timed out waiting for the child process to become ready.');
    }

    /**
     * @return array<string, string>
     */
    private function childEnvironment(): array
    {
        $mysql = config('database.connections.mysql');

        return [
            'APP_ENV' => 'testing',
            'APP_KEY' => (string) config('app.key'),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => (string) $mysql['host'],
            'DB_PORT' => (string) $mysql['port'],
            'DB_DATABASE' => (string) $mysql['database'],
            'DB_USERNAME' => (string) $mysql['username'],
            'DB_PASSWORD' => (string) ($mysql['password'] ?? ''),
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];
    }

    private function bookingScriptPath(): string
    {
        $path = storage_path('framework/testing/book-once.php');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, <<<'PHP'
<?php

declare(strict_types=1);

use App\Actions\CreateAppointment;
use App\Exceptions\AppointmentUnavailable;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;

require __DIR__.'/../../../vendor/autoload.php';

$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

Carbon::setTestNow('2026-11-02 08:00:00');
config([
    'booking.opens_at' => '09:00',
    'booking.closes_at' => '11:00',
    'booking.open_weekdays' => [1, 2, 3, 4, 5],
]);

fwrite(STDOUT, "ready\n");
fflush(STDOUT);

try {
    $app->make(CreateAppointment::class)->handle(
        User::query()->findOrFail((int) $argv[1]),
        (int) $argv[2],
        Carbon::parse($argv[3]),
    );

    fwrite(STDOUT, "ok\n");
    exit(0);
} catch (AppointmentUnavailable) {
    fwrite(STDOUT, "taken\n");
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    exit(1);
}
PHP);

        return $path;
    }
}
