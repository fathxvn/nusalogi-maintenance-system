<?php

namespace App\Services;

use App\Models\BastItem;
use App\Models\OperationHistory;
use App\Models\PrivateEvidenceFile;
use App\Models\ServicePhoto;
use App\Models\ServiceReport;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ReportCorrectionService
{
    public function __construct(
        private readonly M1CommandService $commands,
        private readonly BastContextService $context,
        private readonly ServiceReportWorkflowService $reports,
    ) {}

    public function open(User $actor, int $workOrderId, int $reportId, array $payload): ServiceReport
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->openOnce($actor, $workOrderId, $reportId, $payload);
            } catch (ValidationException $exception) {
                if ($attempt === 2 || ! in_array(['BAST_PARENT_SET_CHANGED'], array_values($exception->errors()), true)) {
                    throw $exception;
                }
            }
        }
        throw new \LogicException('Correction discovery retry exhausted.');
    }

    private function openOnce(User $actor, int $workOrderId, int $reportId, array $payload): ServiceReport
    {
        $this->commands->authorize($actor, ['koordinator']);
        $data = Validator::make($payload, [
            'command_key' => ['required', 'uuid'], 'expected_report_version' => ['required', 'integer', 'min:0'],
            'expected_work_order_version' => ['required', 'integer', 'min:0'], 'author_id' => ['required', 'integer', 'min:1'],
            'category' => ['required', Rule::in(['document_fix', 'technical_rework'])], 'reason' => ['required', 'string', 'max:10000'],
        ])->validate();
        foreach (['expected_report_version', 'expected_work_order_version', 'author_id'] as $field) {
            $data[$field] = (int) $data[$field];
        }
        $hint = ServiceReport::query()->where('work_order_id', $workOrderId)->findOrFail($reportId);
        $pending = BastItem::query()->join('bast_versions', 'bast_versions.id', '=', 'bast_items.version_id')
            ->join('service_reports', 'service_reports.id', '=', 'bast_items.report_revision_id')
            ->where('service_reports.scope_item_id', $hint->scope_item_id)->where('bast_versions.status', 'pending')->pluck('bast_items.version_id')->all();
        $discovery = $this->context->discover([$reportId], BastContextService::ids($pending), [$data['author_id']]);
        $history = $this->commands->run($actor, $data['command_key'], 'report.correction.open', [
            'work_order_id' => $workOrderId, 'report_id' => $reportId, 'input' => $data,
        ], ['koordinator'], fn (): array => $this->context->lockRoots($actor, $discovery),
            function (array $context, User $current) use ($data, $discovery, $workOrderId, $reportId): array {
                $wo = $context['workOrders']->get($workOrderId);
                $reports = $this->context->lockReports($context);
                $previous = $reports->get($reportId);
                $scope = $context['scopes']->get($previous?->scope_item_id);
                if (! $wo || ! $previous || ! $scope || (int) $scope->work_order_id !== $workOrderId || $scope->disposition !== 'active'
                    || ! in_array($wo->status, ['in_progress', 'rework', 'pending_review', 'awaiting_acceptance'], true)) {
                    $this->commands->conflict('report_id', 'REPORT_STATE_CONFLICT');
                }
                if ((int) $wo->lock_version !== $data['expected_work_order_version'] || $previous->lock_version !== $data['expected_report_version']) {
                    $this->commands->conflict('report_id', 'REPORT_VERSION_CONFLICT');
                }
                $head = $reports->where('scope_item_id', $scope->id)->sortByDesc('revision_no')->first();
                if (! $head?->is($previous) || ! in_array($previous->review_status, ['approved', 'needs_revision'], true)) {
                    $this->commands->conflict('report_id', 'REPORT_REVISION_CONFLICT');
                }
                if ($previous->review_status === 'needs_revision') {
                    $review = OperationHistory::query()->where('action', 'report.review')->where('subject_type', 'service_report')
                        ->where('subject_id', $previous->id)->where('to_state', 'needs_revision')->lockForUpdate()->sole();
                    if (($review->facts['category'] ?? null) !== $data['category']) {
                        $this->commands->conflict('category', 'REWORK_CATEGORY_MISMATCH');
                    }
                }
                $packages = $this->context->lockPackages($discovery['documentIds']);
                $claims = $this->context->claims([$scope->id]);
                if ($claims->contains('status', 'accepted')) {
                    $this->commands->conflict('scope_item_id', 'ACCEPTED_SCOPE_IMMUTABLE');
                }
                $invalidated = [];
                foreach ($claims as $claim) {
                    $version = $packages['versions']->get((int) $claim->version_id);
                    $items = $packages['items']->where('version_id', $claim->version_id);
                    if (! $version || array_diff($items->pluck('report_revision_id')->all(), $reports->modelKeys()) !== []) {
                        $this->commands->conflict('version_id', 'BAST_PARENT_SET_CHANGED');
                    }
                    $invalidated[$version->id] = $version;
                }
                $author = $context['resources']->get($data['author_id']);
                if (! $author || ! $author->hasRole('teknisi') || ! $author->isActive() || ! $author->isApproved()
                    || ! $context['memberships']->contains(fn (object $membership): bool => (int) $membership->work_order_id === $workOrderId && (int) $membership->user_id === (int) $author->id)) {
                    throw ValidationException::withMessages(['author_id' => 'Pilih teknisi anggota WO yang aktif dan approved.']);
                }
                $visits = $context['visits']->where('work_order_id', $workOrderId)->modelKeys();
                if ($data['category'] === 'technical_rework' && $context['bookings']->whereIn('visit_id', $visits)->contains('status', 'in_progress')) {
                    $this->commands->conflict('work_order_id', 'ACTIVE_BOOKING_BLOCKS_REWORK');
                }
                $photos = ServicePhoto::query()->where('service_report_id', $previous->id)->orderBy('id')->lockForUpdate()->get();
                $files = PrivateEvidenceFile::query()->whereIn('id', $photos->pluck('evidence_file_id'))->orderBy('id')->lockForUpdate()->get();
                if ($files->contains(fn (PrivateEvidenceFile $file): bool => $file->state !== 'ready')) {
                    $this->commands->conflict('file', 'EVIDENCE_NOT_READY');
                }
                foreach ($invalidated as $version) {
                    $version->forceFill(['status' => 'superseded', 'reason' => $data['reason'], 'lock_version' => $version->lock_version + 1])->save();
                }
                $next = $this->reports->createSuccessor($previous, $author, $photos);
                if ($data['category'] === 'technical_rework') {
                    $this->reports->transition($wo, $current, 'rework', $data['reason']);
                } elseif ($wo->status === 'awaiting_acceptance') {
                    $this->reports->transition($wo, $current, 'pending_review', $data['reason']);
                }

                return ['subject_type' => 'service_report', 'subject_id' => $next->id, 'from_state' => $previous->review_status, 'to_state' => 'draft', 'reason' => $data['reason'],
                    'facts' => ['work_order_id' => $wo->id, 'scope_item_id' => $scope->id, 'previous_revision_id' => $previous->id,
                        'report_id' => $next->id, 'revision_no' => $next->revision_no, 'author_id' => $author->id,
                        'previous_author_id' => (int) $previous->created_by, 'category' => $data['category'],
                        'superseded_version_ids' => array_keys($invalidated), 'work_order_status' => $wo->status, 'work_order_version' => (int) $wo->lock_version]];
            });

        return ServiceReport::query()->findOrFail($history->subject_id);
    }
}
