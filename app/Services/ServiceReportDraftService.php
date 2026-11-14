<?php

namespace App\Services;

use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ServiceReportDraftService
{
    private const CONTENT_FIELDS = [
        'diagnosis',
        'action_taken',
        'result',
        'recommendation',
    ];

    public function __construct(
        private readonly M1CommandService $commands,
    ) {}

    public function createDraft(
        User $actor,
        int $workOrderId,
        int $scopeItemId,
        array $payload,
    ): ServiceReport {
        $this->commands->authorize($actor, ['teknisi']);

        $data = Validator::make($payload, [
            'command_key' => ['required', 'uuid'],
            'expected_work_order_version' => [
                'required',
                'integer',
                'min:0',
            ],
            'diagnosis' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'action_taken' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'result' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'recommendation' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ])->validate();

        $commandKey = $data['command_key'];

        unset($data['command_key']);

        $data['expected_work_order_version'] =
            (int) $data['expected_work_order_version'];

        foreach (self::CONTENT_FIELDS as $field) {
            $value = $data[$field] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            $data[$field] = $value === '' ? null : $value;
        }

        $history = $this->commands->run(
            $actor,
            $commandKey,
            'report.draft.create',
            [
                'work_order_id' => $workOrderId,
                'scope_item_id' => $scopeItemId,
                'input' => $data,
            ],
            ['teknisi'],
            function () use ($actor, $workOrderId, $scopeItemId): array {
                $workOrder = WorkOrder::query()
                    ->lockForUpdate()
                    ->findOrFail($workOrderId);

                $isMember = $workOrder->technicians()
                    ->where('users.id', $actor->id)
                    ->exists();

                if (! $isMember) {
                    throw new AuthorizationException(
                        'Hanya teknisi anggota WO yang boleh membuat laporan.',
                    );
                }

                $scopes = $workOrder->scopeItems()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $scope = $scopes->find($scopeItemId);

                if (! $scope) {
                    throw (new ModelNotFoundException)->setModel(
                        WorkOrderScopeItem::class,
                        [$scopeItemId],
                    );
                }

                return compact('workOrder', 'scopes', 'scope');
            },
            function (array $context, User $current) use ($data): array {
                [
                    'workOrder' => $workOrder,
                    'scopes' => $scopes,
                    'scope' => $scope,
                ] = $context;

                if ((int) $workOrder->lock_version
                    !== $data['expected_work_order_version']) {
                    $this->commands->conflict(
                        'expected_work_order_version',
                        'WORK_ORDER_VERSION_CONFLICT',
                    );
                }

                $hasRequiredScope = $scopes->contains(
                    fn (WorkOrderScopeItem $item): bool => $item->disposition === 'active'
                        && $item->is_required,
                );

                if (! in_array($workOrder->origin_kind, ['ticket', 'manual'], true)
                    || blank($workOrder->acceptance_context_ref)
                    || ! $hasRequiredScope) {
                    $this->commands->conflict(
                        'work_order_id',
                        'WORK_ORDER_M1_BASELINE_REQUIRED',
                    );
                }

                if (! in_array($workOrder->status, ['in_progress', 'rework'], true)) {
                    $this->commands->conflict(
                        'work_order_id',
                        'WORK_ORDER_STATE_CONFLICT',
                    );
                }

                if ($scope->disposition !== 'active') {
                    $this->commands->conflict(
                        'scope_item_id',
                        'SCOPE_STATE_CONFLICT',
                    );
                }

                $existing = ServiceReport::query()
                    ->where('scope_item_id', $scope->id)
                    ->orderBy('revision_no')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($existing->isNotEmpty()) {
                    $this->commands->conflict(
                        'scope_item_id',
                        'REPORT_ALREADY_EXISTS',
                    );
                }

                $report = new ServiceReport;

                $report->forceFill([
                    'work_order_id' => $workOrder->id,
                    'scope_item_id' => $scope->id,
                    'created_by' => $current->id,
                    'revision_no' => 1,
                    'previous_revision_id' => null,
                    'diagnosis' => $data['diagnosis'],
                    'action_taken' => $data['action_taken'],
                    'result' => $data['result'],
                    'recommendation' => $data['recommendation'],
                    'review_status' => 'draft',
                    'reviewed_by' => null,
                    'review_notes' => null,
                    'submitted_at' => null,
                    'reviewed_at' => null,
                    'checklist_snapshot' => null,
                    'usage_snapshot' => null,
                    'asset_results' => null,
                    'lock_version' => 0,
                ])->save();

                return [
                    'subject_type' => 'service_report',
                    'subject_id' => $report->id,
                    'to_state' => 'draft',
                    'facts' => [
                        'work_order_id' => $workOrder->id,
                        'scope_item_id' => $scope->id,
                        'report_id' => $report->id,
                        'revision_no' => $report->revision_no,
                        'lock_version' => $report->lock_version,
                        'expected_work_order_version' => $data['expected_work_order_version'],
                        'work_order_status' => $workOrder->status,
                    ],
                ];
            },
        );

        return ServiceReport::query()
            ->where('work_order_id', $workOrderId)
            ->where('scope_item_id', $scopeItemId)
            ->findOrFail($history->subject_id);
    }

    public function updateDraft(
        User $actor,
        int $workOrderId,
        int $reportId,
        array $payload,
    ): ServiceReport {
        $this->commands->authorize($actor, ['teknisi']);

        $data = Validator::make($payload, [
            'command_key' => ['required', 'uuid'],
            'expected_report_version' => ['required', 'integer', 'min:0'],
            'diagnosis' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'action_taken' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'result' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'recommendation' => ['sometimes', 'nullable', 'string', 'max:10000'],
        ])->validate();

        $commandKey = $data['command_key'];

        unset($data['command_key']);

        $data['expected_report_version'] =
            (int) $data['expected_report_version'];

        foreach (self::CONTENT_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }

            $value = $data[$field];

            if (is_string($value)) {
                $value = trim($value);
            }

            $data[$field] = $value === '' ? null : $value;
        }

        $changes = array_intersect_key(
            $data,
            array_flip(self::CONTENT_FIELDS),
        );

        if ($changes === []) {
            throw ValidationException::withMessages([
                'report' => 'Isi sedikitnya satu field laporan yang akan diperbarui.',
            ]);
        }

        $history = $this->commands->run(
            $actor,
            $commandKey,
            'report.draft.update',
            [
                'work_order_id' => $workOrderId,
                'report_id' => $reportId,
                'input' => $data,
            ],
            ['teknisi'],
            function () use ($actor, $workOrderId, $reportId): array {
                $workOrder = WorkOrder::query()
                    ->lockForUpdate()
                    ->findOrFail($workOrderId);

                $isMember = $workOrder->technicians()
                    ->where('users.id', $actor->id)
                    ->exists();

                if (! $isMember) {
                    throw new AuthorizationException(
                        'Hanya teknisi anggota WO yang boleh mengubah laporan.',
                    );
                }

                $scopes = $workOrder->scopeItems()
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                $report = ServiceReport::query()
                    ->where('work_order_id', $workOrder->id)
                    ->findOrFail($reportId);

                if ((int) $report->created_by !== (int) $actor->id) {
                    throw new AuthorizationException(
                        'Hanya penulis laporan yang boleh memperbarui draft ini.',
                    );
                }

                return compact('workOrder', 'scopes');
            },
            function (array $context, User $current) use (
                $reportId,
                $data,
                $changes,
            ): array {
                [
                    'workOrder' => $workOrder,
                    'scopes' => $scopes,
                ] = $context;

                $report = ServiceReport::query()
                    ->where('work_order_id', $workOrder->id)
                    ->lockForUpdate()
                    ->findOrFail($reportId);

                if ((int) $report->created_by !== (int) $current->id) {
                    throw new AuthorizationException(
                        'Hanya penulis laporan yang boleh memperbarui draft ini.',
                    );
                }

                if ($report->lock_version !== $data['expected_report_version']) {
                    $this->commands->conflict(
                        'expected_report_version',
                        'REPORT_VERSION_CONFLICT',
                    );
                }

                $scope = $scopes->find($report->scope_item_id);

                if (! $scope || (int) $report->revision_no < 1) {
                    $this->commands->conflict(
                        'report_id',
                        'REPORT_M1_BASELINE_REQUIRED',
                    );
                }

                $hasRequiredScope = $scopes->contains(
                    fn (WorkOrderScopeItem $item): bool => $item->disposition === 'active'
                        && $item->is_required,
                );

                if (! in_array($workOrder->origin_kind, ['ticket', 'manual'], true)
                    || blank($workOrder->acceptance_context_ref)
                    || ! $hasRequiredScope) {
                    $this->commands->conflict(
                        'work_order_id',
                        'WORK_ORDER_M1_BASELINE_REQUIRED',
                    );
                }

                if (! in_array(
                    $workOrder->status,
                    ['in_progress', 'rework', 'pending_review'],
                    true,
                )) {
                    $this->commands->conflict(
                        'work_order_id',
                        'WORK_ORDER_STATE_CONFLICT',
                    );
                }

                if ($scope->disposition !== 'active') {
                    $this->commands->conflict(
                        'scope_item_id',
                        'SCOPE_STATE_CONFLICT',
                    );
                }

                if ($report->review_status !== 'draft'
                    || $report->submitted_at !== null
                    || $report->reviewed_at !== null
                    || $report->reviewed_by !== null
                    || $report->review_notes !== null) {
                    $this->commands->conflict(
                        'report_id',
                        'REPORT_STATE_CONFLICT',
                    );
                }

                $latest = ServiceReport::query()
                    ->where('scope_item_id', $scope->id)
                    ->orderByDesc('revision_no')
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->first();

                if (! $latest || (int) $latest->id !== (int) $report->id) {
                    $this->commands->conflict(
                        'report_id',
                        'REPORT_REVISION_CONFLICT',
                    );
                }

                $versionBefore = $report->lock_version;

                $report->forceFill(array_merge($changes, [
                    'lock_version' => $versionBefore + 1,
                ]))->save();

                return [
                    'subject_type' => 'service_report',
                    'subject_id' => $report->id,
                    'from_state' => 'draft',
                    'to_state' => 'draft',
                    'facts' => [
                        'work_order_id' => $workOrder->id,
                        'scope_item_id' => $scope->id,
                        'report_id' => $report->id,
                        'revision_no' => $report->revision_no,
                        'expected_report_version' => $data['expected_report_version'],
                        'lock_version' => $report->lock_version,
                        'changed_fields' => array_keys($changes),
                        'work_order_status' => $workOrder->status,
                    ],
                ];
            },
        );

        return ServiceReport::query()
            ->where('work_order_id', $workOrderId)
            ->findOrFail($history->subject_id);
    }
}
