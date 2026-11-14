<?php

namespace App\Services;

use App\Models\BastItem;
use App\Models\BastVersion;
use App\Models\MaterialUsage;
use App\Models\OperationHistory;
use App\Models\PrivateEvidenceFile;
use App\Models\ServicePhoto;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class WorkOrderLifecycleService
{
    public function __construct(
        private readonly M1CommandService $commands,
        private readonly ReportContextService $reports,
        private readonly ServiceReportWorkflowService $workflow,
        private readonly BastContextService $acceptance,
        private readonly BastService $bast,
    ) {}

    public function change(User $actor, int $workOrderId, string $action, array $payload): WorkOrder
    {
        abort_unless(in_array($action, ['hold', 'resume', 'cancel', 'context'], true), 404);
        $roles = in_array($action, ['hold', 'resume'], true) ? ['koordinator', 'teknisi'] : ['koordinator'];
        $actor = $this->commands->authorize($actor, $roles);
        $data = Validator::make($payload, [
            'command_key' => ['required', 'uuid'], 'expected_work_order_version' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:10000'],
            'acceptance_context_ref' => [$action === 'context' ? 'required' : 'prohibited', 'string', 'max:150'],
        ])->validate();
        $data['expected_work_order_version'] = (int) $data['expected_work_order_version'];
        $resources = $this->reports->discoverResources($workOrderId, $actor);
        $history = $this->commands->run($actor, $data['command_key'], 'work_order.'.$action,
            ['work_order_id' => $workOrderId, 'input' => $data], $roles,
            fn (): array => $this->reports->lockExecutionRoots($actor, $workOrderId, null, $resources),
            function (array $context, User $current) use ($data, $action): array {
                $wo = $context['workOrder'];
                $this->reports->assertBaseline($wo, $context['scopes']);
                if ($wo->lock_version !== $data['expected_work_order_version']) {
                    $this->commands->conflict('expected_work_order_version', 'WORK_ORDER_VERSION_CONFLICT');
                }
                $from = $wo->status;
                $facts = ['work_order_id' => $wo->id];
                if ($action === 'hold') {
                    if (! in_array($from, ['assigned', 'in_progress', 'rework'], true)) {
                        $this->commands->conflict('work_order_id', 'WORK_ORDER_STATE_CONFLICT');
                    }
                    if ($context['bookings']->contains('status', 'in_progress')) {
                        $this->commands->conflict('work_order_id', 'ACTIVE_BOOKING_BLOCKS_HOLD');
                    }
                    $facts['resume_to'] = $from;
                    $this->workflow->transition($wo, $current, 'on_hold', $data['reason']);
                } elseif ($action === 'resume') {
                    if ($from !== 'on_hold') {
                        $this->commands->conflict('work_order_id', 'WORK_ORDER_STATE_CONFLICT');
                    }
                    $hold = OperationHistory::query()->where('subject_type', 'work_order')->where('subject_id', $wo->id)
                        ->where('action', 'work_order.hold')->orderByDesc('id')->lockForUpdate()->first();
                    $resumeTo = $hold?->facts['resume_to'] ?? null;
                    if (! in_array($resumeTo, ['assigned', 'in_progress', 'rework'], true)) {
                        $this->commands->conflict('work_order_id', 'HOLD_PROVENANCE_REQUIRED');
                    }
                    $facts['hold_history_id'] = $hold->id;
                    $this->workflow->transition($wo, $current, $resumeTo, $data['reason']);
                } elseif ($action === 'context') {
                    if (in_array($from, ['closed', 'cancelled'], true)) {
                        $this->commands->conflict('work_order_id', 'WORK_ORDER_STATE_CONFLICT');
                    }
                    $woReports = $wo->reports()->orderBy('id')->lockForUpdate()->get();
                    if (BastItem::query()->whereIn('report_revision_id', $woReports->modelKeys())->lockForUpdate()->first()) {
                        $this->commands->conflict('acceptance_context_ref', 'CONTEXT_REFERENCED_BY_BAST');
                    }
                    $facts['previous_context'] = $wo->acceptance_context_ref;
                    $facts['acceptance_context_ref'] = trim($data['acceptance_context_ref']);
                    if ($facts['acceptance_context_ref'] === '') {
                        throw ValidationException::withMessages(['acceptance_context_ref' => 'Referensi konteks wajib diisi.']);
                    }
                    $wo->forceFill(['acceptance_context_ref' => $facts['acceptance_context_ref'], 'lock_version' => $wo->lock_version + 1])->save();
                } else {
                    $movements = MaterialUsage::query()->whereIn('scope_item_id', $context['scopes']->modelKeys())->orderBy('id')->lockForUpdate()->get();
                    $woReports = $wo->reports()->orderBy('id')->lockForUpdate()->get();
                    $hasActual = $context['bookings']->contains(fn ($booking): bool => $booking->actual_start_at !== null || $booking->actual_end_at !== null);
                    $hold = $from === 'on_hold' ? OperationHistory::query()->where('subject_type', 'work_order')->where('subject_id', $wo->id)
                        ->where('action', 'work_order.hold')->orderByDesc('id')->lockForUpdate()->first() : null;
                    if (! in_array($from, ['pending', 'assigned', 'on_hold'], true) || ($from === 'on_hold' && ($hold?->facts['resume_to'] ?? null) !== 'assigned')
                        || $wo->started_at !== null || $hasActual || $movements->isNotEmpty() || $woReports->isNotEmpty()) {
                        $this->commands->conflict('work_order_id', 'CANCELLATION_AUTHORITY_REQUIRED');
                    }
                    $cancelledBookings = [];
                    $cancelledVisits = [];
                    foreach ($context['visits'] as $visit) {
                        if (in_array($visit->status, ['completed', 'in_progress'], true)) {
                            $this->commands->conflict('work_order_id', 'CANCELLATION_AUTHORITY_REQUIRED');
                        }
                        if (! in_array($visit->status, ['draft', 'confirmed'], true)) {
                            continue;
                        }
                        $bookings = $context['bookings']->where('visit_id', $visit->id);
                        if (($visit->status === 'draft' && $bookings->isNotEmpty()) || ($visit->status === 'confirmed' && ! $bookings->contains('status', 'confirmed'))) {
                            $this->commands->conflict('work_order_id', 'VISIT_BOOKING_STATE_ANOMALY');
                        }
                        foreach ($bookings->where('status', 'confirmed') as $booking) {
                            $booking->forceFill(['status' => 'cancelled', 'ended_reason' => $data['reason']])->save();
                            $cancelledBookings[] = $booking->id;
                        }
                        $visit->forceFill(['status' => 'cancelled', 'lock_version' => $visit->lock_version + 1])->save();
                        $cancelledVisits[] = $visit->id;
                    }
                    $scopeIds = [];
                    foreach ($context['scopes']->where('disposition', 'active') as $scope) {
                        $scope->forceFill(['disposition' => 'cancelled', 'retired_at' => now(), 'retirement_reason' => $data['reason']])->save();
                        $scopeIds[] = $scope->id;
                    }
                    $facts += ['cancelled_booking_ids' => $cancelledBookings, 'cancelled_visit_ids' => $cancelledVisits,
                        'cancelled_scope_ids' => $scopeIds, 'material_disposition' => 'no_usage_recorded', 'membership_preserved' => true];
                    $this->workflow->transition($wo, $current, 'cancelled', $data['reason']);
                }

                return ['subject_type' => 'work_order', 'subject_id' => $wo->id, 'from_state' => $from, 'to_state' => $wo->status,
                    'reason' => $data['reason'], 'facts' => [...$facts, 'lock_version' => $wo->lock_version, 'expected_work_order_version' => $data['expected_work_order_version']]];
            });

        return WorkOrder::query()->findOrFail($history->subject_id);
    }

    public function close(User $actor, int $workOrderId, array $payload): WorkOrder
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->closeOnce($actor, $workOrderId, $payload);
            } catch (ValidationException $exception) {
                if ($attempt === 2 || ! in_array(['CLOSURE_PROOF_CHANGED'], array_values($exception->errors()), true)) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Closure discovery retry exhausted.');
    }

    private function closeOnce(User $actor, int $workOrderId, array $payload): WorkOrder
    {
        $this->commands->authorize($actor, ['koordinator']);
        if (($payload['closure_mode'] ?? 'normal') !== 'normal' || array_key_exists('exception', $payload) || array_key_exists('exception_approved', $payload)) {
            $this->commands->conflict('closure_mode', 'EXCEPTION_CLOSURE_DISABLED');
        }
        $data = Validator::make($payload, ['command_key' => ['required', 'uuid'], 'expected_work_order_version' => ['required', 'integer', 'min:0'],
            'reason' => ['nullable', 'string', 'max:10000']])->validate();
        $data['expected_work_order_version'] = (int) $data['expected_work_order_version'];
        $scopeIds = WorkOrderScopeItem::query()->where('work_order_id', $workOrderId)->where('disposition', 'active')->where('is_required', true)->pluck('id');
        $versionIds = BastContextService::ids(BastItem::query()->join('service_reports', 'service_reports.id', '=', 'bast_items.report_revision_id')
            ->join('bast_versions', 'bast_versions.id', '=', 'bast_items.version_id')->whereIn('service_reports.scope_item_id', $scopeIds)
            ->where('bast_versions.status', 'accepted')->pluck('bast_items.version_id')->all());
        foreach ($versionIds as $id) {
            $version = $this->acceptance->visibleVersion($actor, $id);
            $this->bast->verifyEvidence($version);
        }
        $discovery = $this->acceptance->discover([], $versionIds, [], [$workOrderId]);
        $history = $this->commands->run($actor, $data['command_key'], 'work_order.close', ['work_order_id' => $workOrderId, 'input' => $data], ['koordinator'],
            fn (): array => $this->acceptance->lockRoots($actor, $discovery),
            function (array $context, User $current) use ($data, $discovery, $workOrderId): array {
                $wo = $context['workOrders']->get($workOrderId);
                if (! $wo) {
                    $this->commands->conflict('work_order_id', 'WORK_ORDER_M1_BASELINE_REQUIRED');
                }
                if ($wo->lock_version !== $data['expected_work_order_version']) {
                    $this->commands->conflict('expected_work_order_version', 'WORK_ORDER_VERSION_CONFLICT');
                }
                if ($wo->status !== 'awaiting_acceptance') {
                    $this->commands->conflict('work_order_id', 'WORK_ORDER_STATE_CONFLICT');
                }
                $scopes = $context['scopes']->where('work_order_id', $wo->id);
                $this->reports->assertBaseline($wo, $scopes);
                $required = $scopes->where('is_required', true)->where('disposition', 'active');
                if ($required->isEmpty()) {
                    $this->commands->conflict('work_order_id', 'REQUIRED_SCOPES_MISSING');
                }
                $visits = $context['visits']->where('work_order_id', $wo->id);
                if ($context['bookings']->whereIn('visit_id', $visits->modelKeys())->contains(fn ($booking): bool => in_array($booking->status, ['confirmed', 'in_progress'], true))) {
                    $this->commands->conflict('work_order_id', 'ACTIVE_BOOKINGS_BLOCK_CLOSURE');
                }
                $reports = $this->acceptance->lockReports($context);
                $packages = $this->acceptance->lockPackages($discovery['documentIds']);
                $claims = $this->acceptance->claims($required->modelKeys());
                $proof = [];
                $usedVersions = [];
                foreach ($required as $scope) {
                    $head = $reports->where('scope_item_id', $scope->id)->sortByDesc('revision_no')->first();
                    if (! $head || $head->review_status !== 'approved' || $head->revision_no < 1 || $head->submitted_at === null || $head->reviewed_at === null || ! $head->reviewed_by) {
                        $this->commands->conflict('work_order_id', 'ALL_REQUIRED_SCOPES_MUST_BE_ACCEPTED');
                    }
                    $claim = $claims->first(fn (object $claim): bool => (int) $claim->scope_item_id === (int) $scope->id && $claim->status === 'accepted' && (int) $claim->report_revision_id === (int) $head->id);
                    if (! $claim) {
                        $this->commands->conflict('work_order_id', 'ALL_REQUIRED_SCOPES_MUST_BE_ACCEPTED');
                    }
                    $version = $packages['versions']->get((int) $claim->version_id);
                    if (! $version) {
                        $this->commands->conflict('work_order_id', 'CLOSURE_PROOF_CHANGED');
                    }
                    $document = $packages['documents']->get((int) $version->document_id);
                    $items = $packages['items']->where('version_id', $version->id);
                    $this->assertAcceptedProof($version, $document, $items, $context, $reports);
                    $review = OperationHistory::query()->where('action', 'report.review')->where('subject_type', 'service_report')->where('subject_id', $head->id)
                        ->where('to_state', 'approved')->where('actor_id', $head->reviewed_by)->lockForUpdate()->first();
                    $decision = OperationHistory::query()->where('action', 'bast.decision')->where('subject_type', 'bast_version')->where('subject_id', $version->id)
                        ->where('to_state', 'accepted')->where('actor_id', $version->recorded_by)->lockForUpdate()->first();
                    if (! $review || ! $decision) {
                        $this->commands->conflict('work_order_id', 'CLOSURE_PROVENANCE_REQUIRED');
                    }
                    $usedVersions[$version->id] = $version;
                    $proof[] = ['scope_item_id' => $scope->id, 'report_revision_id' => $head->id, 'bast_item_id' => (int) $claim->id,
                        'bast_version_id' => $version->id, 'issued_file_id' => (int) $version->issued_file_id, 'decision_file_id' => (int) $version->decision_file_id];
                }
                $itemReports = $packages['items']->whereIn('version_id', array_keys($usedVersions))->pluck('report_revision_id')->all();
                $photos = ServicePhoto::query()->whereIn('service_report_id', $itemReports)->orderBy('id')->lockForUpdate()->get();
                $fileIds = BastContextService::ids([...$photos->pluck('evidence_file_id')->filter()->all(),
                    ...array_column($proof, 'issued_file_id'), ...array_column($proof, 'decision_file_id')]);
                $files = PrivateEvidenceFile::query()->whereIn('id', $fileIds)->orderBy('id')->lockForUpdate()->get();
                if ($photos->contains(fn (ServicePhoto $photo): bool => $photo->evidence_file_id === null)
                    || $files->count() !== count($fileIds) || $files->contains(fn (PrivateEvidenceFile $file): bool => $file->state !== 'ready')) {
                    $this->commands->conflict('file', 'EVIDENCE_NOT_READY');
                }
                $reason = $data['reason'] ?? 'Acceptance seluruh scope wajib terverifikasi melalui BAST.';
                $this->workflow->transition($wo, $current, 'closed', $reason);
                $wo->forceFill(['closed_at' => now()])->save();

                return ['subject_type' => 'work_order', 'subject_id' => $wo->id, 'from_state' => 'awaiting_acceptance', 'to_state' => 'closed', 'reason' => $reason,
                    'facts' => ['work_order_id' => $wo->id, 'lock_version' => $wo->lock_version, 'closure_evidence' => $proof,
                        'evidence_file_ids' => $fileIds, 'ticket_id' => $wo->ticket_id, 'ticket_resolution' => 'manual_queue',
                        'expected_work_order_version' => $data['expected_work_order_version']]];
            });

        return WorkOrder::query()->findOrFail($history->subject_id);
    }

    private function assertAcceptedProof(BastVersion $version, object $document, Collection $items, array $context, Collection $reports): void
    {
        if ($version->status !== 'accepted' || ! $version->issued_file_id || ! $version->decision_file_id || ! $version->issued_at
            || ! $version->decided_at || ! $version->recorded_at || ! $version->recorded_by
            || blank($version->signer_name) || blank($version->signer_capacity) || blank($version->authority_ref)
            || BastContextService::ids(array_column($version->scope_snapshot['items'] ?? [], 'report_revision_id')) !== BastContextService::ids($items->pluck('report_revision_id')->all())) {
            $this->commands->conflict('work_order_id', 'CLOSURE_PROVENANCE_REQUIRED');
        }
        foreach ($items as $item) {
            $report = $reports->get((int) $item->report_revision_id);
            $wo = $report ? $context['workOrders']->get((int) $report->work_order_id) : null;
            $scope = $report ? $context['scopes']->get((int) $report->scope_item_id) : null;
            $site = $wo ? $context['sites']->get((int) $wo->customer_site_id) : null;
            if (! $report || ! $wo || ! $scope || ! $site) {
                $this->commands->conflict('work_order_id', 'CLOSURE_PROOF_CHANGED');
            }
            $head = $reports->where('scope_item_id', $scope->id)->sortByDesc('revision_no')->first();
            if (! $head?->is($report) || $report->review_status !== 'approved' || $scope->disposition !== 'active'
                || (int) $scope->work_order_id !== (int) $wo->id || (int) $site->customer_id !== (int) $document->customer_id
                || $wo->acceptance_context_ref !== $document->acceptance_context_ref) {
                $this->commands->conflict('work_order_id', 'ALL_REQUIRED_SCOPES_MUST_BE_ACCEPTED');
            }
        }
    }
}
