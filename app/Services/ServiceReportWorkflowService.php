<?php

namespace App\Services;

use App\Models\MaterialUsage;
use App\Models\OperationHistory;
use App\Models\PrivateEvidenceFile;
use App\Models\ServicePhoto;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ServiceReportWorkflowService
{
    public function __construct(
        private readonly M1CommandService $commands,
        private readonly ReportContextService $context,
        private readonly PrivateEvidenceService $files,
    ) {}

    public function submit(User $actor, int $workOrderId, int $reportId, array $payload): ServiceReport
    {
        $this->commands->authorize($actor, ['teknisi']);
        $data = $this->input($payload);
        $visible = $this->context->visibleReport($actor, $workOrderId, $reportId);
        foreach ($visible->photos()->with('evidenceFile')->get() as $photo) {
            if (! $photo->evidenceFile || $photo->evidenceFile->state !== 'ready') {
                $this->commands->conflict('file', 'EVIDENCE_NOT_READY');
            }
            $this->files->verify($photo->evidenceFile);
        }
        $resources = $this->context->discoverResources($workOrderId, $actor);
        $history = $this->commands->run($actor, $data['command_key'], 'report.submit', [
            'work_order_id' => $workOrderId, 'report_id' => $reportId, 'input' => $data,
        ], ['teknisi'], fn (): array => $this->context->lockExecutionRoots($actor, $workOrderId, $reportId, $resources),
            function (array $context, User $current) use ($data, $reportId): array {
                $wo = $context['workOrder'];
                $this->expectedWorkOrder($wo, $data);
                if ($context['bookings']->contains('status', 'in_progress')) {
                    $this->commands->conflict('work_order_id', 'ACTIVE_BOOKING_BLOCKS_SUBMISSION');
                }
                $hint = $wo->reports()->findOrFail($reportId);
                $usages = MaterialUsage::query()->where('scope_item_id', $hint->scope_item_id)->orderBy('id')->lockForUpdate()->get();
                $allReports = $wo->reports()->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                $report = $this->context->draft($context, $reportId, (int) $data['expected_report_version']);
                if ($wo->status === 'rework') {
                    $this->commands->conflict('work_order_id', 'REWORK_MUST_START_BEFORE_SUBMISSION');
                }
                Validator::make($report->toArray(), [
                    'diagnosis' => ['required', 'string'], 'action_taken' => ['required', 'string'], 'result' => ['required', 'string'],
                    'checklist_snapshot' => ['required', 'array', 'min:1'],
                ], ['required' => ':attribute wajib dilengkapi sebelum submit.'])->validate();
                $photos = $report->photos()->orderBy('id')->lockForUpdate()->get();
                $evidence = PrivateEvidenceFile::query()->whereIn('id', $photos->pluck('evidence_file_id'))->orderBy('id')->lockForUpdate()->get();
                if ($photos->contains(fn (ServicePhoto $photo): bool => $photo->evidence_file_id === null)
                    || $evidence->contains(fn (PrivateEvidenceFile $file): bool => $file->state !== 'ready')
                    || $evidence->count() !== $photos->pluck('evidence_file_id')->unique()->count()) {
                    $this->commands->conflict('file', 'EVIDENCE_NOT_READY');
                }
                $snapshot = $usages->map(fn (MaterialUsage $usage): array => [
                    'id' => $usage->id, 'item_description' => $usage->item_description, 'quantity' => $usage->quantity,
                    'unit_code' => $usage->unit_code, 'movement_kind' => $usage->movement_kind,
                    'adjusts_usage_id' => $usage->adjusts_usage_id, 'supply_source' => $usage->supply_source,
                    'disposition' => $usage->disposition, 'recorded_by' => $usage->recorded_by,
                    'recorded_at' => $usage->recorded_at->toISOString(), 'reason' => $usage->reason,
                ])->all();
                $report->forceFill(['review_status' => 'submitted', 'submitted_at' => now(),
                    'usage_snapshot' => $snapshot, 'lock_version' => $report->lock_version + 1])->save();
                $allReports->put($report->id, $report);
                $this->advance($wo, $current, $context['scopes'], $allReports);

                return ['subject_type' => 'service_report', 'subject_id' => $report->id, 'from_state' => 'draft', 'to_state' => 'submitted',
                    'facts' => ['work_order_id' => $wo->id, 'scope_item_id' => $report->scope_item_id,
                        'revision_no' => $report->revision_no, 'lock_version' => $report->lock_version,
                        'work_order_version' => (int) $wo->lock_version, 'work_order_status' => $wo->status,
                        'movement_ids' => $usages->modelKeys(), 'evidence_file_ids' => $evidence->modelKeys()]];
            });

        return ServiceReport::query()->findOrFail($history->subject_id);
    }

    public function review(User $actor, int $workOrderId, int $reportId, array $payload): ServiceReport
    {
        $this->commands->authorize($actor, ['koordinator']);
        $data = $this->input($payload, [
            'decision' => ['required', Rule::in(['approved', 'needs_revision'])],
            'category' => ['nullable', 'required_if:decision,needs_revision', Rule::in(['document_fix', 'technical_rework'])],
            'reason' => ['nullable', 'required_if:decision,needs_revision', 'string', 'max:10000'],
        ]);
        $resources = $this->context->discoverResources($workOrderId, $actor);
        $history = $this->commands->run($actor, $data['command_key'], 'report.review', [
            'work_order_id' => $workOrderId, 'report_id' => $reportId, 'input' => $data,
        ], ['koordinator'], fn (): array => $this->context->lockExecutionRoots($actor, $workOrderId, $reportId, $resources, false),
            function (array $context, User $current) use ($reportId, $data): array {
                $wo = $context['workOrder'];
                $this->expectedWorkOrder($wo, $data);
                $report = $this->context->head($context, $reportId, (int) $data['expected_report_version']);
                if (! in_array($wo->status, ['in_progress', 'pending_review', 'rework'], true)
                    || $report->review_status !== 'submitted' || $report->submitted_at === null
                    || $report->reviewed_at !== null || $report->reviewed_by !== null) {
                    $this->commands->conflict('report_id', 'REPORT_STATE_CONFLICT');
                }
                if ((int) $report->created_by === (int) $current->id) {
                    throw new AuthorizationException('Reviewer tidak boleh menyetujui atau mereview laporannya sendiri.');
                }
                if ($data['decision'] === 'needs_revision' && $data['category'] === 'technical_rework' && $context['bookings']->contains('status', 'in_progress')) {
                    $this->commands->conflict('work_order_id', 'ACTIVE_BOOKING_BLOCKS_REWORK');
                }
                $report->forceFill(['review_status' => $data['decision'], 'reviewed_by' => $current->id,
                    'reviewed_at' => now(), 'review_notes' => $data['reason'] ?? null, 'lock_version' => $report->lock_version + 1])->save();
                if ($data['decision'] === 'needs_revision' && $data['category'] === 'technical_rework') {
                    $this->transition($wo, $current, 'rework', $data['reason']);
                } else {
                    $this->advance($wo, $current);
                }

                return ['subject_type' => 'service_report', 'subject_id' => $report->id, 'from_state' => 'submitted', 'to_state' => $data['decision'],
                    'reason' => $data['reason'] ?? null, 'facts' => ['work_order_id' => $wo->id, 'scope_item_id' => $report->scope_item_id,
                        'category' => $data['category'] ?? null, 'revision_no' => $report->revision_no,
                        'lock_version' => $report->lock_version, 'work_order_version' => (int) $wo->lock_version, 'work_order_status' => $wo->status]];
            });

        return ServiceReport::query()->findOrFail($history->subject_id);
    }

    public function revise(User $actor, int $workOrderId, int $reportId, array $payload): ServiceReport
    {
        $this->commands->authorize($actor, ['teknisi']);
        $data = $this->input($payload);
        $history = $this->commands->run($actor, $data['command_key'], 'report.revise', [
            'work_order_id' => $workOrderId, 'report_id' => $reportId, 'input' => $data,
        ], ['teknisi'], fn (): array => $this->context->lockRoots($actor, $workOrderId, $reportId),
            function (array $context, User $current) use ($reportId, $data): array {
                $wo = $context['workOrder'];
                $this->expectedWorkOrder($wo, $data);
                $previous = $this->context->head($context, $reportId, (int) $data['expected_report_version']);
                if ($previous->review_status !== 'needs_revision' || ! in_array($wo->status, ['pending_review', 'rework', 'in_progress'], true)) {
                    $this->commands->conflict('report_id', 'REPORT_STATE_CONFLICT');
                }
                $review = OperationHistory::query()->where('action', 'report.review')->where('subject_type', 'service_report')
                    ->where('subject_id', $previous->id)->lockForUpdate()->sole();
                $successor = $this->successor($previous, $current);

                return ['subject_type' => 'service_report', 'subject_id' => $successor->id, 'from_state' => 'needs_revision', 'to_state' => 'draft',
                    'facts' => ['work_order_id' => $wo->id, 'scope_item_id' => $successor->scope_item_id,
                        'previous_revision_id' => $previous->id, 'revision_no' => $successor->revision_no,
                        'category' => $review->facts['category'], 'author_id' => $current->id, 'lock_version' => 0]];
            });

        return ServiceReport::query()->findOrFail($history->subject_id);
    }

    public function successor(ServiceReport $previous, User $author): ServiceReport
    {
        $photos = $previous->photos()->orderBy('id')->lockForUpdate()->get();
        $files = PrivateEvidenceFile::query()->whereIn('id', $photos->pluck('evidence_file_id'))->orderBy('id')->lockForUpdate()->get();
        if ($files->contains(fn (PrivateEvidenceFile $file): bool => $file->state !== 'ready')) {
            $this->commands->conflict('file', 'EVIDENCE_NOT_READY');
        }

        return $this->createSuccessor($previous, $author, $photos);
    }

    /** Caller holds WO/scope/user/report/photo/file locks in A1 order. @param Collection<int, ServicePhoto> $photos */
    public function createSuccessor(ServiceReport $previous, User $author, Collection $photos): ServiceReport
    {
        $report = new ServiceReport;
        $report->forceFill([
            ...$previous->only(['work_order_id', 'scope_item_id', 'diagnosis', 'action_taken', 'result', 'recommendation', 'checklist_snapshot', 'asset_results']),
            'created_by' => $author->id, 'revision_no' => $previous->revision_no + 1, 'previous_revision_id' => $previous->id,
            'review_status' => 'draft', 'usage_snapshot' => null, 'lock_version' => 0,
        ])->save();
        foreach ($photos as $photo) {
            $copy = $photo->replicate();
            $copy->service_report_id = $report->id;
            $copy->save();
        }

        return $report;
    }

    public function advance(WorkOrder $wo, User $actor, ?Collection $scopes = null, ?Collection $reports = null): void
    {
        $scopes ??= $wo->scopeItems()->orderBy('id')->lockForUpdate()->get();
        $required = $scopes->filter(fn ($scope): bool => $scope->disposition === 'active' && $scope->is_required);
        $reports ??= $wo->reports()->orderBy('id')->lockForUpdate()->get();
        $reports = $reports->whereIn('scope_item_id', $required->modelKeys());
        $heads = $reports->groupBy('scope_item_id')->map(fn (Collection $revisions): ServiceReport => $revisions->sortByDesc('revision_no')->first());
        if ($required->isEmpty() || $heads->count() !== $required->count()) {
            return;
        }
        if ($heads->every(fn (ServiceReport $head): bool => $head->review_status === 'approved')) {
            $this->transition($wo, $actor, 'awaiting_acceptance', 'Seluruh scope wajib memiliki head approved.');
        } elseif ($heads->every(fn (ServiceReport $head): bool => in_array($head->review_status, ['submitted', 'approved'], true))) {
            $this->transition($wo, $actor, 'pending_review', 'Seluruh scope wajib telah diajukan untuk review.');
        }
    }

    public function transition(WorkOrder $wo, User $actor, string $status, string $reason): void
    {
        if ($wo->status === $status) {
            return;
        }
        $from = $wo->status;
        $values = ['status' => $status, 'lock_version' => (int) $wo->lock_version + 1];
        if ($status === 'pending_review' && $from === 'in_progress') {
            $values['field_finished_at'] = now();
        }
        $wo->forceFill($values)->save();
        $wo->updates()->create(['changed_by' => $actor->id, 'from_status' => $from, 'to_status' => $status, 'notes' => $reason]);
    }

    private function input(array $payload, array $extra = []): array
    {
        $data = Validator::make($payload, [
            'command_key' => ['required', 'uuid'], 'expected_report_version' => ['required', 'integer', 'min:0'],
            'expected_work_order_version' => ['required', 'integer', 'min:0'], ...$extra,
        ])->validate();
        $data['expected_report_version'] = (int) $data['expected_report_version'];
        $data['expected_work_order_version'] = (int) $data['expected_work_order_version'];

        return $data;
    }

    private function expectedWorkOrder(WorkOrder $wo, array $data): void
    {
        if ((int) $wo->lock_version !== $data['expected_work_order_version']) {
            $this->commands->conflict('expected_work_order_version', 'WORK_ORDER_VERSION_CONFLICT');
        }
    }
}
