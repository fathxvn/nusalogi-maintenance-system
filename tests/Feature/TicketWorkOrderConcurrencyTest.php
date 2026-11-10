<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\OperationHistory;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class TicketWorkOrderConcurrencyTest extends TestCase
{
    // Fixtures must be committed and visible to both independent PHP processes.
    use DatabaseMigrations;

    /** TW05 */
    public function test_concurrent_identical_commands_commit_one_work_order_scope_and_history(): void
    {
        $coordinator = User::factory()->create(['role' => 'koordinator']);
        $site = $this->site();
        $payload = $this->payload($site);

        $results = $this->race($coordinator, $coordinator, [$payload, $payload]);

        $this->assertTrue($results[0]['ok']);
        $this->assertTrue($results[1]['ok']);
        $this->assertSame($results[0]['work_order_id'], $results[1]['work_order_id']);
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('work_order_scope_items', 1);
        $this->assertDatabaseCount('operation_history', 1);
    }

    /** TW06, including an in-flight conflicting command */
    public function test_concurrent_different_payloads_with_one_key_return_one_409(): void
    {
        $coordinator = User::factory()->create(['role' => 'koordinator']);
        $site = $this->site();
        $payload = $this->payload($site);

        $results = $this->race($coordinator, $coordinator, [$payload, [...$payload, 'title' => 'Different scope of work']]);

        $successes = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
        $failures = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));
        $this->assertCount(1, $successes);
        $this->assertCount(1, $failures);
        $this->assertSame(409, $failures[0]['status']);
        $this->assertSame(['COMMAND_KEY_REUSED'], $failures[0]['errors']['command_key']);
        $this->assertSame($successes[0]['work_order_id'], WorkOrder::query()->sole()->id);
        $this->assertDatabaseCount('work_order_scope_items', 1);
        $this->assertDatabaseCount('operation_history', 1);
    }

    /** TW18 */
    public function test_different_commands_for_one_ticket_version_commit_once_and_return_one_version_conflict(): void
    {
        $coordinator = User::factory()->create(['role' => 'koordinator']);
        $site = $this->site();
        $ticket = ServiceTicket::factory()->create([
            'site_id' => $site->id, 'customer_id' => $site->customer_id,
            'status' => 'approved', 'coverage_disposition' => 'covered',
        ]);
        $first = $this->payload($site, $ticket);
        $second = [...$first, 'command_key' => (string) Str::uuid()];

        $results = $this->race($ticket, $coordinator, [$first, $second]);

        $successes = array_values(array_filter($results, fn (array $result): bool => $result['ok']));
        $failures = array_values(array_filter($results, fn (array $result): bool => ! $result['ok']));
        $this->assertCount(1, $successes);
        $this->assertCount(1, $failures);
        $this->assertSame(409, $failures[0]['status']);
        $this->assertSame(['TICKET_VERSION_CONFLICT'], $failures[0]['errors']['expected_ticket_version']);
        $this->assertSame(1, $ticket->fresh()->lock_version);
        $this->assertSame('in_fulfillment', $ticket->fresh()->status);
        $this->assertSame(1, $ticket->workOrders()->count());
        $this->assertDatabaseCount('work_order_scope_items', 1);
        $this->assertSame($successes[0]['work_order_id'], OperationHistory::query()->sole()->subject_id);
    }

    /**
     * Hold the serialization row until both workers have reached their locking query.
     * Both processes are running while the parent holds that lock. Releasing it allows
     * the real service and MySQL to decide which transaction can commit.
     *
     * @param  list<array<string, mixed>>  $payloads
     * @return list<array<string, mixed>>
     */
    private function race(Model $root, User $actor, array $payloads): array
    {
        $directory = sys_get_temp_dir().'/nusalogi-m1-'.Str::uuid();
        $this->assertTrue(mkdir($directory, 0700));
        $mysql = config('database.connections.mysql');
        $environment = [
            'APP_ENV' => 'testing',
            'APP_KEY' => config('app.key'),
            'APP_CONFIG_CACHE' => $directory.'/unused-config.php',
            'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'testing', 'DB_URL' => '',
            'DB_HOST' => (string) $mysql['host'], 'DB_PORT' => (string) $mysql['port'],
            'DB_USERNAME' => (string) $mysql['username'], 'DB_PASSWORD' => (string) $mysql['password'],
            'DB_SOCKET' => (string) $mysql['unix_socket'],
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ];
        $connection = DB::connection();
        $this->assertSame(0, $connection->transactionLevel());
        $processes = [];
        $readyFiles = [];

        try {
            $connection->beginTransaction();
            $root->newQuery()->whereKey($root->getKey())->lockForUpdate()->firstOrFail();

            foreach ($payloads as $index => $payload) {
                $readyFiles[] = $readyPath = $directory.'/worker-'.$index.'.ready';
                $process = new Process([
                    PHP_BINARY, base_path('tests/Fixtures/m1-work-order-worker.php'),
                ], base_path(), $environment);
                $process->setInput(json_encode([
                    'actor_id' => $actor->id, 'payload' => $payload,
                    'ready_path' => $readyPath, 'lock_table' => $root->getTable(),
                ], JSON_THROW_ON_ERROR));
                $process->setTimeout(30);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 15;
            foreach ($readyFiles as $index => $readyPath) {
                while (! is_file($readyPath)) {
                    if (! $processes[$index]->isRunning()) {
                        $this->fail('Worker exited before the lock barrier: '.$processes[$index]->getErrorOutput());
                    }
                    if (microtime(true) >= $deadline) {
                        $this->fail('Workers did not reach their locking queries within 15 seconds.');
                    }
                    // This polls real child processes rather than simulating concurrent requests.
                    usleep(10_000);
                }
                $this->assertTrue($processes[$index]->isRunning());
            }

            $connection->commit();
            $results = [];
            foreach ($processes as $process) {
                $exitCode = $process->wait();
                $this->assertSame(0, $exitCode, $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }

            return $results;
        } finally {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            foreach (glob($directory.'/*') as $path) {
                unlink($path);
            }
            rmdir($directory);
        }
    }

    private function site(): CustomerSite
    {
        $customer = Customer::query()->create(['name' => 'Concurrency customer']);

        return $customer->sites()->create(['site_name' => 'Office', 'address' => 'Bekasi']);
    }

    private function payload(CustomerSite $site, ?ServiceTicket $ticket = null): array
    {
        return [
            'command_key' => (string) Str::uuid(),
            'origin_kind' => $ticket ? 'ticket' : 'manual',
            'ticket_id' => $ticket?->id, 'expected_ticket_version' => $ticket?->lock_version,
            'manual_reason' => $ticket ? null : 'Direct operational request',
            'initial_scope' => ['description' => 'Restore feed', 'acceptance_criteria' => 'Live feed works'],
            'customer_site_id' => $site->id, 'title' => 'Camera inspection',
            'description' => 'Camera offline', 'service_type' => 'maintenance',
            'category' => 'cctv', 'priority' => 'high', 'asset_ids' => [],
        ];
    }
}
