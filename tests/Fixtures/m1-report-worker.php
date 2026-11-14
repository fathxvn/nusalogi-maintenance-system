<?php

use App\Models\User;
use App\Services\MaterialUsageService;
use App\Services\ServiceReportDraftService;
use App\Services\ServiceReportWorkflowService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

$metadata = [];
$signalled = false;
$snapshotCount = null;
$action = 'create';

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

    $action = $input['action'] ?? 'create';

    if (! in_array($action, ['create', 'update', 'material', 'submit', 'review', 'revise'], true)) {
        throw new RuntimeException('Unsupported concurrency action.');
    }

    DB::connection()->beforeExecuting(
        function (string $sql, array $bindings) use (
            $input,
            &$metadata,
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
            // proceeds through the shared work order mutex.
            $snapshotCount = DB::table('service_reports')
                ->where('work_order_id', $input['work_order_id'])
                ->count();

            if ($input['probe_lock'] ?? false) {
                // PDO bypasses this callback. NOWAIT proves both live transactions
                // reached a conflicting parent lock before either may proceed.
                try {
                    $probe = DB::connection()->getPdo()->prepare($sql.' nowait');
                    $probe->execute($bindings);
                    throw new RuntimeException('Expected a contended serialization lock.');
                } catch (PDOException $exception) {
                    if (($exception->errorInfo[1] ?? null) !== 3572) {
                        throw $exception;
                    }
                    $metadata['lock_contended'] = true;
                }
            }

            $ready = json_encode([
                ...$metadata,
                'snapshot_report_count' => $snapshotCount,
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

    if (in_array($action, ['submit', 'review', 'revise'], true)) {
        $report = $app->make(ServiceReportWorkflowService::class)->{$action}(
            $actor, (int) $input['work_order_id'], (int) $input['report_id'], $input['payload']);
    } else {
        $service = $app->make($action === 'material' ? MaterialUsageService::class : ServiceReportDraftService::class);
        $report = $action === 'create'
            ? $service->createDraft($actor, (int) $input['work_order_id'], (int) $input['scope_item_id'], $input['payload'])
            : ($action === 'material'
                ? $service->record($actor, (int) $input['work_order_id'], (int) $input['report_id'], $input['payload'])
                : $service->updateDraft($actor, (int) $input['work_order_id'], (int) $input['report_id'], $input['payload']));
    }
    $result = ['ok' => true, 'report_id' => $report->id, 'lock_version' => $report->lock_version];
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
    'snapshot_report_count' => $snapshotCount,
], JSON_THROW_ON_ERROR);
