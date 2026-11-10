<?php

namespace App\Services;

use App\Models\OperationHistory;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class M1CommandService
{
    /** @param list<string> $roles */
    public function authorize(User $actor, array $roles, bool $lock = false): User
    {
        $query = User::query()->whereKey($actor->id);
        $current = ($lock ? $query->lockForUpdate() : $query)->first();

        if (! $current || ! $current->isActive() || ! $current->isApproved() || ! $current->hasAnyRole($roles)) {
            throw new AuthorizationException('Anda tidak memiliki akses.');
        }

        return $current;
    }

    /**
     * Normal HTTP entry points call this as the outer transaction so deadlock retries
     * repeat the entire command. Root locks precede the actor mutex.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $roles
     * @param  Closure(): mixed  $lockRoots
     * @param  Closure(mixed, User): array<string, mixed>  $write
     */
    public function run(
        User $actor,
        string $key,
        string $action,
        array $payload,
        array $roles,
        Closure $lockRoots,
        Closure $write,
    ): OperationHistory {
        $this->authorize($actor, $roles);
        $digest = hash('sha256', json_encode([
            'action' => $action,
            'payload' => $this->canonical($payload),
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $key, $action, $digest, $roles, $lockRoots, $write): OperationHistory {
            $context = $lockRoots();
            $current = $this->authorize($actor, $roles, true);

            // A locking read observes the winner's committed history under REPEATABLE READ.
            $existing = OperationHistory::query()
                ->where('actor_id', $current->id)
                ->where('command_key', $key)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->request_digest, $digest)) {
                    $this->conflict('command_key', 'COMMAND_KEY_REUSED');
                }

                return $existing;
            }

            $result = $write($context, $current);

            return OperationHistory::query()->create([
                'actor_id' => $current->id,
                'actor_role' => $current->role,
                'command_key' => $key,
                'action' => $action,
                'request_digest' => $digest,
                'subject_type' => $result['subject_type'],
                'subject_id' => $result['subject_id'],
                'from_state' => $result['from_state'] ?? null,
                'to_state' => $result['to_state'] ?? null,
                'reason' => $result['reason'] ?? null,
                'facts' => $result['facts'],
                'occurred_at' => now(),
            ]);
        }, 3);
    }

    public function conflict(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => $code])->status(409);
    }

    /**
     * @param  array<string|int, mixed>  $payload
     * @return array<string|int, mixed>
     */
    private function canonical(array $payload): array
    {
        if (! array_is_list($payload)) {
            ksort($payload);
        }

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->canonical($value);
            }
        }

        return $payload;
    }
}
