<?php

use App\Models\User;
use App\Services\CreateWorkOrderService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../vendor/autoload.php';

try {
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $connection = config('database.default');
    $database = config("database.connections.{$connection}.database");
    $databaseUrl = config("database.connections.{$connection}.url");

    if (! $app->environment('testing') || $connection !== 'mysql' || $database !== 'testing' || filled($databaseUrl)) {
        throw new RuntimeException('Concurrency workers require the isolated MySQL testing database.');
    }

    $input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    $signalled = false;
    DB::connection()->beforeExecuting(function (string $sql) use ($input, &$signalled): void {
        if (! $signalled && str_contains($sql, 'from `'.$input['lock_table'].'`') && str_contains($sql, 'for update')) {
            $signalled = true;
            if (file_put_contents($input['ready_path'], 'ready') === false) {
                throw new RuntimeException('Cannot signal the concurrency barrier.');
            }
        }
    });

    $actor = User::query()->findOrFail($input['actor_id']);
    $workOrder = $app->make(CreateWorkOrderService::class)->create($actor, $input['payload']);
    echo json_encode(['ok' => true, 'work_order_id' => $workOrder->id], JSON_THROW_ON_ERROR);
} catch (ValidationException $exception) {
    echo json_encode(['ok' => false, 'status' => $exception->status, 'errors' => $exception->errors()], JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage());
    exit(1);
}
