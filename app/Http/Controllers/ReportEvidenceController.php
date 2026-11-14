<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkOrder;
use App\Services\M1CommandService;
use App\Services\PrivateEvidenceService;
use App\Services\ReportEvidenceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReportEvidenceController extends Controller
{
    public function store(Request $request, WorkOrder $workOrder, int $report, M1CommandService $commands, ReportEvidenceService $service): RedirectResponse
    {
        $actor = $commands->authorize($request->user(), ['teknisi']);
        $workOrder = WorkOrder::query()->visibleTo($actor)->findOrFail($workOrder->id);
        $workOrder->reports()->findOrFail($report);
        $request->validate(['file' => ['required', 'file'], 'upload_key' => ['required', 'uuid']]);
        $file = $service->upload($actor, $workOrder->id, $report, $request->file('file'), [
            ...$request->only(['sha256', 'expected_report_version']), 'command_key' => $request->input('upload_key'),
        ]);
        $service->attach($actor, $workOrder->id, $report, [
            ...$request->only(['command_key', 'expected_report_version', 'phase', 'caption']), 'evidence_file_id' => $file->id,
        ]);

        return back()->with('success', 'Evidence privat tersimpan.');
    }

    public function show(Request $request, WorkOrder $workOrder, int $report, int $photo, M1CommandService $commands, PrivateEvidenceService $files): StreamedResponse
    {
        $actor = $commands->authorize($request->user(), User::ROLES);
        $workOrder = WorkOrder::query()->visibleTo($actor)->findOrFail($workOrder->id);
        $target = $workOrder->reports()->findOrFail($report)->photos()->with('evidenceFile')->findOrFail($photo);
        $file = $target->evidenceFile;
        abort_unless($file && $file->state === 'ready', 404);
        $files->verify($file);

        return Storage::disk('evidence')->download($file->storage_key, $file->original_name, [
            'Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
