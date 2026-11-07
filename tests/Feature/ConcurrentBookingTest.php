<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Service;
use App\Models\User;
use Illuminate\Process\Pool;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * @group mysql
 */
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

            $pool->path(base_path())->env($env)->timeout(30)->command([
                PHP_BINARY,
                $script,
                (string) $firstCustomer->id,
                (string) $service->id,
                $startsAt,
            ]);

            $pool->path(base_path())->env($env)->timeout(30)->command([
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
