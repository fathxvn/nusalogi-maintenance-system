<?php

namespace Tests\Feature;

use App\Models\OperationHistory;
use App\Models\ServiceTicket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Services\TicketEvidenceService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class TicketEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_attachment_is_idempotent_versioned_and_never_approves_a_ticket(): void
    {
        Storage::fake('evidence');
        [$actor, $ticket, $upload] = $this->context();
        $service = app(TicketEvidenceService::class);
        $input = $this->uploadPayload($upload);
        $file = $service->upload($actor, $ticket->id, $upload, $input);
        $this->assertSame($file->id, $service->upload($actor, $ticket->id, $upload, $input)->id);
        $attach = $this->attachPayload($file->id);
        $attachment = $service->attach($actor, $ticket->id, $attach);
        $this->assertSame($attachment->id, $service->attach($actor, $ticket->id, $attach)->id);
        $this->assertSame('new', $ticket->fresh()->status);
        $this->assertSame(1, $ticket->fresh()->lock_version);
        $this->assertSame('authorization', $attachment->purpose);
        $this->assertSame(1, $ticket->attachments()->count());
        Storage::disk('evidence')->assertExists($file->storage_key);
        $this->assertSame(1, OperationHistory::query()->where('action', 'ticket.evidence.attach')->count());
        $this->assertConflict(fn () => $service->attach($actor, $ticket->id, [...$attach, 'purpose' => 'resolution']), 'COMMAND_KEY_REUSED');
        $this->assertConflict(fn () => $service->attach($actor, $ticket->id, [...$attach, 'command_key' => (string) Str::uuid()]), 'TICKET_VERSION_CONFLICT');
    }

    public function test_attachment_is_bound_to_parent_purpose_version_and_uploader(): void
    {
        Storage::fake('evidence');
        [$actor, $ticket, $upload] = $this->context();
        $service = app(TicketEvidenceService::class);
        $file = $service->upload($actor, $ticket->id, $upload, $this->uploadPayload($upload));
        $other = ServiceTicket::factory()->create();
        $this->expectException(AuthorizationException::class);
        $service->attach($actor, $other->id, $this->attachPayload($file->id));
    }

    public function test_history_failure_rolls_back_attachment_and_version_and_keeps_ready_bytes(): void
    {
        Storage::fake('evidence');
        [$actor, $ticket, $upload] = $this->context();
        $service = app(TicketEvidenceService::class);
        $file = $service->upload($actor, $ticket->id, $upload, $this->uploadPayload($upload));
        $input = $this->attachPayload($file->id);
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failed): void {
            if (! $failed && str_starts_with($sql, 'insert into `operation_history`')) {
                $failed = true;
                throw new RuntimeException('Attachment history failed');
            }
        });
        try {
            $service->attach($actor, $ticket->id, $input);
            $this->fail('History must be atomic.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Attachment history failed', $exception->getMessage());
        }
        $this->assertDatabaseCount('ticket_attachments', 0);
        $this->assertSame(0, $ticket->fresh()->lock_version);
        $this->assertSame('ready', $file->fresh()->state);
        Storage::disk('evidence')->assertExists($file->storage_key);
        $this->assertNotNull($service->attach($actor, $ticket->id, $input));
    }

    public function test_http_upload_download_parent_checks_and_readonly_terminal_presentation(): void
    {
        $this->withoutVite();
        Storage::fake('evidence');
        [$actor, $ticket, $upload] = $this->context();
        $payload = [...$this->uploadPayload($upload), 'file' => $upload,
            'upload_key' => (string) Str::uuid(), 'command_key' => (string) Str::uuid()];
        $this->actingAs($actor)->post(route('tickets.evidence.store', $ticket), $payload)
            ->assertRedirectToRoute('tickets.show', $ticket)->assertSessionHasNoErrors();
        $attachment = $ticket->attachments()->sole();
        $url = route('tickets.evidence.show', [$ticket, $attachment]);
        $this->actingAs($actor)->get($url)->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'application/octet-stream');
        $coordinator = User::factory()->create(['role' => 'koordinator']);
        $this->actingAs($coordinator)->get($url)->assertOk();
        $other = ServiceTicket::factory()->create();
        $this->actingAs($actor)->get(route('tickets.evidence.show', [$other, $attachment]))->assertNotFound();
        $tech = User::factory()->create(['role' => 'teknisi']);
        $this->actingAs($tech)->get($url)->assertForbidden();
        $this->actingAs($tech)->postJson(route('tickets.evidence.store', $ticket), $payload)->assertForbidden();
        $ticket->forceFill(['status' => 'rejected'])->save();
        $this->actingAs($actor)->get(route('tickets.show', $ticket))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->has('attachments', 1)->where('attachments.0.download_url', $url)->where('evidenceAction', null)->etc());
        $this->actingAs($actor)->post(route('tickets.evidence.store', $ticket), $payload)->assertSessionHasNoErrors();
        $this->assertSame(1, $ticket->attachments()->count());
        $actor->forceFill(['is_active' => false])->save();
        $this->actingAs($actor)->get($url)->assertRedirect(route('login'));
        $this->expectException(AuthorizationException::class);
        app(TicketEvidenceService::class)->attach($actor, $ticket->id, $this->attachPayload((int) $attachment->evidence_file_id));
    }

    public function test_new_terminal_ticket_evidence_and_invalid_bytes_are_rejected(): void
    {
        Storage::fake('evidence');
        [$actor, $ticket, $upload] = $this->context();
        $service = app(TicketEvidenceService::class);
        $this->assertConflict(fn () => $service->upload($actor, $ticket->id, $upload, [...$this->uploadPayload($upload), 'sha256' => str_repeat('0', 64)]), 'EVIDENCE_HASH_MISMATCH');
        $ticket->forceFill(['status' => 'resolved'])->save();
        $this->assertConflict(fn () => $service->upload($actor, $ticket->id, $upload, $this->uploadPayload($upload)), 'TICKET_STATE_CONFLICT');
        $this->assertDatabaseCount('ticket_attachments', 0);
        $this->assertDatabaseCount('private_evidence_files', 0);
    }

    public function test_attachment_reference_is_immutable_even_through_a_bulk_writer(): void
    {
        $attachment = TicketAttachment::factory()->create();
        try {
            DB::table('ticket_attachments')->where('id', $attachment->id)->update(['purpose' => 'resolution']);
            $this->fail('Attachment metadata is immutable.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
        }
        try {
            DB::table('ticket_attachments')->where('id', $attachment->id)->delete();
            $this->fail('Attachment history must remain.');
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
        }
    }

    private function context(): array
    {
        return [User::factory()->create(['role' => 'admin']), ServiceTicket::factory()->create(),
            UploadedFile::fake()->createWithContent('authorization.pdf', "%PDF-1.4\nCustomer instruction\n%%EOF")];
    }

    private function uploadPayload(UploadedFile $file): array
    {
        return ['command_key' => (string) Str::uuid(), 'expected_ticket_version' => 0, 'purpose' => 'authorization', 'sha256' => hash_file('sha256', $file->getRealPath())];
    }

    private function attachPayload(int $fileId): array
    {
        return ['command_key' => (string) Str::uuid(), 'expected_ticket_version' => 0, 'purpose' => 'authorization', 'evidence_file_id' => $fileId];
    }

    private function assertConflict(callable $operation, string $code): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertContains([$code], array_values($exception->errors()));

            return;
        }
        $this->fail('Expected domain conflict.');
    }
}
