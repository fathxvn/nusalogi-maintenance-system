<?php

namespace App\Services;

use App\Models\OperationHistory;
use App\Models\PrivateEvidenceFile;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

class PrivateEvidenceService
{
    public function __construct(private readonly M1CommandService $commands) {}

    /**
     * Registration and promotion are separate durable steps. Storage I/O never runs
     * while domain locks are held. Failed attachment leaves a retained ready orphan.
     *
     * @param  array<string, mixed>  $binding
     * @param  list<string>  $roles
     * @param  Closure(): mixed  $lockRoots
     * @param  Closure(mixed): void  $validateTarget
     */
    public function upload(User $actor, UploadedFile $upload, array $payload, array $binding, array $roles, Closure $lockRoots, Closure $validateTarget): PrivateEvidenceFile
    {
        $this->commands->authorize($actor, $roles);
        $data = Validator::make([...$payload, 'file' => $upload], [
            'command_key' => ['required', 'uuid'],
            'sha256' => ['required', 'string', 'regex:/\A[a-f0-9]{64}\z/'],
            'file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'extensions:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ])->validate();
        $hash = hash_file('sha256', $upload->getRealPath());
        if (! hash_equals($data['sha256'], $hash)) {
            $this->commands->conflict('file', 'EVIDENCE_HASH_MISMATCH');
        }
        $metadata = [
            'original_name' => basename(str_replace('\\', '/', $upload->getClientOriginalName())),
            'media_type' => $upload->getMimeType(),
            'size_bytes' => $upload->getSize(), 'sha256' => $hash,
        ];
        $key = $data['command_key'];
        $history = $this->commands->run($actor, $key, 'evidence.register', [
            'binding' => $binding, 'metadata' => $metadata,
        ], $roles, $lockRoots, function (mixed $context, User $current) use ($validateTarget, $metadata, $binding): array {
            $validateTarget($context);
            $file = PrivateEvidenceFile::query()->create([
                ...$metadata, 'storage_disk' => 'evidence', 'storage_key' => 'objects/'.Str::uuid(),
                'uploaded_by' => $current->id, 'uploaded_at' => now(), 'state' => 'staged',
            ]);

            return ['subject_type' => 'evidence_file', 'subject_id' => $file->id, 'to_state' => 'staged',
                'facts' => ['binding' => $binding, 'file_id' => $file->id, 'sha256' => $file->sha256]];
        });
        $file = PrivateEvidenceFile::query()->findOrFail($history->subject_id);
        if ($file->state === 'quarantined') {
            $this->commands->conflict('file', 'EVIDENCE_QUARANTINED');
        }
        if ($file->state === 'staged') {
            $this->writeOnce($file, $upload);
        }
        $this->verify($file);

        return DB::transaction(function () use ($actor, $roles, $file, $history): PrivateEvidenceFile {
            $current = $this->commands->authorize($actor, $roles, true);
            $locked = PrivateEvidenceFile::query()->lockForUpdate()->findOrFail($file->id);
            if ($locked->state === 'ready') {
                return $locked;
            }
            if ($locked->state !== 'staged') {
                $this->commands->conflict('file', 'EVIDENCE_QUARANTINED');
            }
            $locked->state = 'ready';
            $locked->save();
            OperationHistory::query()->create([
                'actor_id' => $current->id, 'actor_role' => $current->role, 'command_key' => (string) Str::uuid(),
                'action' => 'evidence.promote', 'request_digest' => $history->request_digest,
                'subject_type' => 'evidence_file', 'subject_id' => $locked->id,
                'from_state' => 'staged', 'to_state' => 'ready', 'occurred_at' => now(),
                'facts' => ['registration_history_id' => $history->id, 'file_id' => $locked->id, 'sha256' => $locked->sha256],
            ]);

            return $locked;
        }, 3);
    }

    /** @param array<string, mixed> $binding */
    public function lockReady(User $actor, int $fileId, array $binding): PrivateEvidenceFile
    {
        $file = PrivateEvidenceFile::query()->lockForUpdate()->findOrFail($fileId);
        $registration = OperationHistory::query()->where('subject_type', 'evidence_file')->where('subject_id', $file->id)
            ->where('action', 'evidence.register')->firstOrFail();
        $registeredBinding = $registration->facts['binding'];
        ksort($registeredBinding);
        ksort($binding);
        if ($registeredBinding !== $binding || (int) $file->uploaded_by !== (int) $actor->id) {
            throw new AuthorizationException('Ditolak: evidence tidak terdaftar untuk objek dan pengunggah ini.');
        }
        if ($file->state !== 'ready') {
            $this->commands->conflict('file', 'EVIDENCE_NOT_READY');
        }

        return $file;
    }

    public function verify(PrivateEvidenceFile $file): void
    {
        if ($file->storage_disk !== 'evidence' || ! preg_match('/\Aobjects\/[a-f0-9-]{36}\z/', $file->storage_key)) {
            $this->commands->conflict('file', 'EVIDENCE_INTEGRITY_FAILED');
        }
        $stream = Storage::disk('evidence')->readStream($file->storage_key);
        if (! is_resource($stream)) {
            $this->commands->conflict('file', 'EVIDENCE_INTEGRITY_FAILED');
        }
        try {
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $stream);
            if ($size !== $file->size_bytes || ! hash_equals($file->sha256, hash_final($hash))) {
                $this->commands->conflict('file', 'EVIDENCE_INTEGRITY_FAILED');
            }
        } finally {
            fclose($stream);
        }
    }

    /** Exclusive creation prevents retries or concurrent uploads overwriting a key. */
    private function writeOnce(PrivateEvidenceFile $file, UploadedFile $upload): void
    {
        $path = Storage::disk('evidence')->path($file->storage_key);
        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Private evidence directory could not be created.');
        }
        $target = @fopen($path, 'xb');
        if ($target === false) {
            if (is_file($path)) {
                return;
            }
            throw new RuntimeException('Private evidence could not be stored.');
        }
        try {
            chmod($path, 0600);
            $source = fopen($upload->getRealPath(), 'rb');
            if ($source === false) {
                throw new RuntimeException('Uploaded evidence could not be read.');
            }
            try {
                if (stream_copy_to_stream($source, $target) !== $file->size_bytes || ! fflush($target)) {
                    throw new RuntimeException('Private evidence copy failed.');
                }
            } finally {
                fclose($source);
            }
        } finally {
            fclose($target);
        }
    }
}
