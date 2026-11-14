<?php

namespace App\Http\Controllers;

use App\Models\ServiceTicket;
use App\Services\M1CommandService;
use App\Services\PrivateEvidenceService;
use App\Services\TicketEvidenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketEvidenceController extends Controller
{
    public function store(Request $request, ServiceTicket $ticket, M1CommandService $commands, TicketEvidenceService $service): RedirectResponse
    {
        $actor = $commands->authorize($request->user(), ['admin', 'koordinator']);
        $request->validate(['file' => ['required', 'file'], 'upload_key' => ['required', 'uuid']]);
        $file = $service->upload($actor, $ticket->id, $request->file('file'), [
            ...$request->only(['sha256', 'expected_ticket_version', 'purpose']), 'command_key' => $request->input('upload_key'),
        ]);
        $service->attach($actor, $ticket->id, [...$request->only(['command_key', 'expected_ticket_version', 'purpose']), 'evidence_file_id' => $file->id]);

        return redirect()->route('tickets.show', $ticket)->with('success', 'Lampiran privat ticket tersimpan.');
    }

    public function show(Request $request, ServiceTicket $ticket, int $attachment, M1CommandService $commands, PrivateEvidenceService $files): StreamedResponse
    {
        $commands->authorize($request->user(), ['admin', 'koordinator']);
        $file = $ticket->attachments()->with('evidenceFile')->findOrFail($attachment)->evidenceFile;
        abort_unless($file && $file->state === 'ready', 404);
        $files->verify($file);

        return Storage::disk('evidence')->download($file->storage_key, $file->original_name, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ]);
    }
}
