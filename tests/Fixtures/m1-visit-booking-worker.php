<?php

use App\Models\User;
use App\Services\CancelVisitBookingService;
use App\Services\VisitSchedulingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$metadata = [];
$signalled = false;
$snapshotCount = null;
$action = 'confirm';

try {
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $connection = config('database.default');
    $database = config("database.connections.{$connection}.database");
    $databaseUrl = config("database.connections.{$connection}.url");

    if (! $app->environment('testing')
        || $connection !== 'mysql'
        || $database !== 'testing'
        || filled($databaseUrl)) {
        throw new RuntimeException(
            'Concurrency workers require the isolated MySQL testing database.',
        );
    }

    $runtime = DB::selectOne(
        'SELECT DATABASE() AS database_name,
                CONNECTION_ID() AS connection_id,
                @@transaction_isolation AS isolation_level'
    );

    if ($runtime->database_name !== 'testing') {
        throw new RuntimeException(
            'The actual database connection must use testing.',
        );
    }

    $metadata = [
        'connection_id' => (int) $runtime->connection_id,
        'isolation_level' => $runtime->isolation_level,
    ];

    $input = json_decode(
        stream_get_contents(STDIN),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    if (! is_array($input)
        || ! in_array(
            $input['barrier_table'] ?? null,
            ['users', 'work_orders'],
            true,
        )) {
        throw new RuntimeException('Invalid concurrency barrier input.');
    }

    $action = $input['action'] ?? 'confirm';

    if (! in_array($action, ['confirm', 'cancel'], true)) {
        throw new RuntimeException('Unsupported concurrency action.');
    }

    DB::connection()->beforeExecuting(
        function (string $sql, array $bindings) use (
            $input,
            $metadata,
            &$signalled,
            &$snapshotCount,
        ): void {
            $matches = str_contains(
                $sql,
                'from `'.$input['barrier_table'].'`',
            ) && str_contains($sql, 'for update')
                && count($bindings) === 1
                && (string) $bindings[0] === (string) $input['barrier_id'];

            if ($signalled || ! $matches) {
                return;
            }

            if (DB::connection()->transactionLevel() < 1) {
                throw new RuntimeException(
                    'The serialization lock must run inside a transaction.',
                );
            }

            $signalled = true;

            // Both workers establish their snapshots before either command
            // proceeds through the shared technician mutex.
            $snapshotCount = DB::table('visit_bookings')
                ->where('technician_id', $input['technician_id'])
                ->count();

            $ready = json_encode([
                ...$metadata,
                'snapshot_booking_count' => $snapshotCount,
            ], JSON_THROW_ON_ERROR);

            if (file_put_contents($input['ready_path'], $ready) === false) {
                throw new RuntimeException(
                    'Cannot signal the concurrency barrier.',
                );
            }

            // Optional gate lets the parent test exercise both orderings.
            if (isset($input['go_path'])) {
                $deadline = microtime(true) + 20;

                while (true) {
                    clearstatcache(true, $input['go_path']);

                    if (is_file($input['go_path'])) {
                        break;
                    }

                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException(
                            'Parent did not release the worker gate.',
                        );
                    }

                    usleep(10_000);
                }
            }
        },
    );

    $actor = User::query()->findOrFail($input['actor_id']);

    if ($action === 'confirm') {
        $visit = $app->make(VisitSchedulingService::class)->confirm(
            $actor,
            (int) $input['visit_id'],
            $input['payload'],
        );

        $result = [
            'ok' => true,
            'visit_id' => $visit->id,
            'visit_status' => $visit->status,
            'lock_version' => $visit->lock_version,
        ];
    } else {
        $booking = $app->make(CancelVisitBookingService::class)->cancel(
            $actor,
            (int) $input['booking_id'],
            $input['payload'],
        );

        $visit = $booking->visit;

        $result = [
            'ok' => true,
            'booking_id' => $booking->id,
            'booking_status' => $booking->status,
            'visit_id' => $visit->id,
            'visit_status' => $visit->status,
            'lock_version' => $visit->lock_version,
        ];
    }
} catch (ValidationException $exception) {
    $result = [
        'ok' => false,
        'status' => $exception->status,
        'errors' => $exception->errors(),
    ];
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        get_class($exception).': '.$exception->getMessage().PHP_EOL,
    );

    exit(1);
}

echo json_encode([
    ...$result,
    ...$metadata,
    'action' => $action,
    'barrier_reached' => $signalled,
    'snapshot_booking_count' => $snapshotCount,
], JSON_THROW_ON_ERROR);