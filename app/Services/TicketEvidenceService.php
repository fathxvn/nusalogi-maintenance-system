<?php

namespace App\Services;

use App\Models\PrivateEvidenceFile;
use App\Models\ServiceTicket;
use App\Models\TicketAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class TicketEvidenceService
{
    public function __construct(private readonly M1CommandService $commands, private readonly PrivateEvidenceService $files) {}

    public function upload(User $actor, int $ticketId, UploadedFile $file, array $payload): PrivateEvidenceFile
    {
        $this->commands->authorize($actor, ['admin', 'koordinator']);
        $data = $this->validate($payload);
        $binding = $this->binding($ticketId, $data);

        return $this->files->upload($actor, $file, $payload, $binding, ['admin', 'koordinator'],
            fn (): ServiceTicket => ServiceTicket::query()->lockForUpdate()->findOrFail($ticketId),
            fn (ServiceTicket $ticket) => $this->assertTarget($ticket, $data));
    }

    public function attach(User $actor, int $ticketId, array $payload): TicketAttachment
    {
        $this->commands->authorize($actor, ['admin', 'koordinator']);
        $data = [...$this->validate($payload), ...Validator::make($payload, [
            'command_key' => ['required', 'uuid'], 'evidence_file_id' => ['required', 'integer', 'min:1'],
        ])->validate()];
        $data['evidence_file_id'] = (int) $data['evidence_file_id'];
        $file = PrivateEvidenceFile::query()->where('uploaded_by', $actor->id)->findOrFail($data['evidence_file_id']);
        $this->files->verify($file);
        $history = $this->commands->run($actor, $data['command_key'], 'ticket.evidence.attach', ['ticket_id' => $ticketId, 'input' => $data], ['admin', 'koordinator'],
            fn (): ServiceTicket => ServiceTicket::query()->lockForUpdate()->findOrFail($ticketId),
            function (ServiceTicket $ticket, User $current) use ($data): array {
                $this->assertTarget($ticket, $data);
                $file = $this->files->lockReady($current, $data['evidence_file_id'], $this->binding($ticket->id, $data));
                $attachment = TicketAttachment::query()->create(['ticket_id' => $ticket->id, 'evidence_file_id' => $file->id,
                    'purpose' => $data['purpose'], 'attached_by' => $current->id, 'attached_at' => now()]);
                $ticket->increment('lock_version');

                return ['subject_type' => 'ticket_attachment', 'subject_id' => $attachment->id, 'to_state' => 'attached',
                    'facts' => ['ticket_id' => $ticket->id, 'purpose' => $data['purpose'], 'file_id' => $file->id,
                        'sha256' => $file->sha256, 'lock_version' => $ticket->lock_version, 'expected_ticket_version' => $data['expected_ticket_version']]];
            });

        return TicketAttachment::query()->where('ticket_id', $ticketId)->findOrFail($history->subject_id);
    }

    private function validate(array $payload): array
    {
        $data = Validator::make($payload, ['expected_ticket_version' => ['required', 'integer', 'min:0'],
            'purpose' => ['required', Rule::in(['complaint', 'authorization', 'resolution'])]])->validate();
        $data['expected_ticket_version'] = (int) $data['expected_ticket_version'];

        return $data;
    }

    private function binding(int $ticketId, array $data): array
    {
        return ['purpose' => 'ticket', 'ticket_id' => $ticketId, 'attachment_purpose' => $data['purpose'], 'expected_ticket_version' => $data['expected_ticket_version']];
    }

    private function assertTarget(ServiceTicket $ticket, array $data): void
    {
        if ($ticket->lock_version !== $data['expected_ticket_version']) {
            $this->commands->conflict('expected_ticket_version', 'TICKET_VERSION_CONFLICT');
        }
        if (in_array($ticket->status, ['resolved', 'rejected', 'duplicate', 'cancelled'], true)) {
            $this->commands->conflict('ticket_id', 'TICKET_STATE_CONFLICT');
        }
    }
}
