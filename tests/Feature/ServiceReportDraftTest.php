<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\CreateWorkOrderService;
use App\Services\ServiceReportDraftService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ServiceReportDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_assigned_technician_can_create_first_draft(): void
    {
        [$technician, $workOrder, $scope] = $this->context();

        $workOrderVersion = $workOrder->lock_version;
        $updatesBefore = $workOrder->updates()->count();

        $payload = $this->payload($workOrder, [
            'diagnosis' => '  Kabel jaringan terlepas.  ',
            'revision_no' => 99,
            'created_by' => 999999,
            'review_status' => 'approved',
            'reviewed_by' => $technician->id,
            'lock_version' => 99,
            'usage_snapshot' => ['forged' => true],
        ]);

        $report = app(ServiceReportDraftService::class)->createDraft(
            $technician,
            $workOrder->id,
            $scope->id,
            $payload,
        );

        $this->assertSame($workOrder->id, (int) $report->work_order_id);
        $this->assertSame($scope->id, $report->scope_item_id);
        $this->assertSame($technician->id, (int) $report->created_by);
        $this->assertSame(1, $report->revision_no);
        $this->assertSame(0, $report->lock_version);
        $this->assertSame('draft', $report->review_status);
        $this->assertSame('Kabel jaringan terlepas.', $report->diagnosis);

        $this->assertNull($report->previous_revision_id);
        $this->assertNull($report->reviewed_by);
        $this->assertNull($report->review_notes);
        $this->assertNull($report->reviewed_at);
        $this->assertNull($report->submitted_at);
        $this->assertNull($report->checklist_snapshot);
        $this->assertNull($report->usage_snapshot);
        $this->assertNull($report->asset_results);

        $this->assertTrue($report->scopeItem->is($scope));
        $this->assertTrue($report->creator->is($technician));

        $this->assertSame('in_progress', $workOrder->fresh()->status);
        $this->assertSame(
            $workOrderVersion,
            $workOrder->fresh()->lock_version,
        );
        $this->assertSame($updatesBefore, $workOrder->updates()->count());

        $history = OperationHistory::query()
            ->where('action', 'report.draft.create')
            ->sole();

        $this->assertSame($technician->id, (int) $history->actor_id);
        $this->assertSame('service_report', $history->subject_type);
        $this->assertSame($report->id, (int) $history->subject_id);
        $this->assertSame('draft', $history->to_state);
        $this->assertSame(
            $scope->id,
            (int) $history->facts['scope_item_id'],
        );
    }

    public function test_identical_retry_returns_same_report_after_wo_changes(): void
    {
        [$technician, $workOrder, $scope] = $this->context();

        $service = app(ServiceReportDraftService::class);
        $payload = $this->payload($workOrder);

        $first = $service->createDraft(
            $technician,
            $workOrder->id,
            $scope->id,
            $payload,
        );

        $createdAt = $first->getRawOriginal('created_at');

        $this->travel(1)->minutes();

        // Simulasikan perubahan WO setelah command pertama committed.
        $workOrder->forceFill([
            'status' => 'on_hold',
            'lock_version' => (int) $workOrder->lock_version + 1,
        ])->save();

        $retry = $service->createDraft(
            $technician,
            $workOrder->id,
            $scope->id,
            $payload,
        );

        $this->assertSame($first->id, $retry->id);
        $this->assertSame(
            $createdAt,
            $retry->getRawOriginal('created_at'),
        );
        $this->assertSame(1, ServiceReport::query()->count());
        $this->assertSame(
            1,
            OperationHistory::query()
                ->where('action', 'report.draft.create')
                ->count(),
        );
        $this->assertSame('on_hold', $workOrder->fresh()->status);
    }

    public function test_new_command_cannot_create_another_initial_draft(): void
    {
        [$technician, $workOrder, $scope] = $this->context();

        $service = app(ServiceReportDraftService::class);

        $first = $service->createDraft(
            $technician,
            $workOrder->id,
            $scope->id,
            $this->payload($workOrder),
        );

        $secondPayload = $this->payload($workOrder);

        $this->assertConflict(
            fn () => $service->createDraft(
                $technician,
                $workOrder->id,
                $scope->id,
                $secondPayload,
            ),
            'scope_item_id',
            'REPORT_ALREADY_EXISTS',
        );

        $this->assertSame(1, ServiceReport::query()->count());

        $this->assertDatabaseHas('service_reports', [
            'id' => $first->id,
            'revision_no' => 1,
        ]);

        $this->assertDatabaseMissing('operation_history', [
            'command_key' => $secondPayload['command_key'],
        ]);
    }

    public function test_command_key_cannot_be_reused_with_changed_content(): void
    {
        [$technician, $workOrder, $scope] = $this->context();

        $service = app(ServiceReportDraftService::class);
        $payload = $this->payload($workOrder);

        $report = $service->createDraft(
            $technician,
            $workOrder->id,
            $scope->id,
            $payload,
        );

        $changedPayload = array_replace($payload, [
            'diagnosis' => 'Isi berbeda dengan command key yang sama.',
        ]);

        $this->assertConflict(
            fn () => $service->createDraft(
                $technician,
                $workOrder->id,
                $scope->id,
                $changedPayload,
            ),
            'command_key',
            'COMMAND_KEY_REUSED',
        );

        $this->assertSame(
            $payload['diagnosis'],
            $report->fresh()->diagnosis,
        );
        $this->assertSame(1, ServiceReport::query()->count());
        $this->assertSame(
            1,
            OperationHistory::query()
                ->where('action', 'report.draft.create')
                ->count(),
        );
    }

    #[DataProvider('unauthorizedActors')]
    public function test_ineligible_actor_cannot_create_draft(
        array $attributes,
        bool $assigned,
    ): void {
        [, $workOrder, $scope] = $this->context();

        $actor = User::factory()->create($attributes);

        if ($assigned) {
            $workOrder->technicians()->attach($actor, [
                'assigned_at' => now(),
            ]);
        }

        $payload = $this->payload($workOrder);

        try {
            app(ServiceReportDraftService::class)->createDraft(
                $actor,
                $workOrder->id,
                $scope->id,
                $payload,
            );

            $this->fail('Aktor tidak eligible harus ditolak.');
        } catch (AuthorizationException) {
            $this->assertSame(0, ServiceReport::query()->count());
            $this->assertSame('in_progress', $workOrder->fresh()->status);

            $this->assertDatabaseMissing('operation_history', [
                'command_key' => $payload['command_key'],
            ]);
        }
    }

    public function test_scope_from_another_wo_is_rejected(): void
    {
        [$technician, $workOrder] = $this->context();
        [, , $otherScope] = $this->context();

        $payload = $this->payload($workOrder);

        try {
            app(ServiceReportDraftService::class)->createDraft(
                $technician,
                $workOrder->id,
                $otherScope->id,
                $payload,
            );

            $this->fail('Scope dari WO lain harus ditolak.');
        } catch (ModelNotFoundException) {
            $this->assertSame(0, ServiceReport::query()->count());

            $this->assertDatabaseMissing('operation_history', [
                'command_key' => $payload['command_key'],
            ]);
        }
    }

    public function test_stale_wo_version_does_not_create_report_or_history(): void
    {
        [$technician, $workOrder, $scope] = $this->context();

        $payload = $this->payload($workOrder, [
            'expected_work_order_version' => (int) $workOrder->lock_version + 1,
        ]);

        $this->assertConflict(
            fn () => app(ServiceReportDraftService::class)->createDraft(
                $technician,
                $workOrder->id,
                $scope->id,
                $payload,
            ),
            'expected_work_order_version',
            'WORK_ORDER_VERSION_CONFLICT',
        );

        $this->assertSame(0, ServiceReport::query()->count());
        $this->assertSame('in_progress', $workOrder->fresh()->status);

        $this->assertDatabaseMissing('operation_history', [
            'command_key' => $payload['command_key'],
        ]);
    }

    public function test_replay_still_rejects_a_disabled_actor(): void
    {
        [$technician, $workOrder, $scope] = $this->context();

        $service = app(ServiceReportDraftService::class);
        $payload = $this->payload($workOrder);

        $report = $service->createDraft(
            $technician,
            $workOrder->id,
            $scope->id,
            $payload,
        );

        $technician->forceFill(['is_active' => false])->save();

        try {
            $service->createDraft(
                $technician,
                $workOrder->id,
                $scope->id,
                $payload,
            );

            $this->fail('Replay oleh akun nonaktif harus ditolak.');
        } catch (AuthorizationException) {
            $this->assertSame(1, ServiceReport::query()->count());

            $this->assertDatabaseHas('service_reports', [
                'id' => $report->id,
                'created_by' => $technician->id,
            ]);

            $this->assertSame(
                1,
                OperationHistory::query()
                    ->where('action', 'report.draft.create')
                    ->count(),
            );
        }
    }

    public function test_author_can_partially_update_draft_without_client_control_fields(): void
    {
        [$technician, $workOrder, $scope] = $this->context();
        $service = app(ServiceReportDraftService::class);
        $report = $service->createDraft($technician, $workOrder->id, $scope->id, $this->payload($workOrder));
        $before = $report->getAttributes();
        $version = $workOrder->lock_version;
        $payload = $this->updatePayload($report, [
            'diagnosis' => '  Diagnosis diperbarui.  ', 'recommendation' => '  ',
            'created_by' => 99999, 'work_order_id' => 99999, 'scope_item_id' => 99999,
            'revision_no' => 42, 'previous_revision_id' => 99999, 'review_status' => 'approved',
            'submitted_at' => now(), 'reviewed_by' => $technician->id,
            'review_notes' => 'Forged', 'lock_version' => 99, 'usage_snapshot' => ['forged'],
        ]);

        $updated = $service->updateDraft($technician, $workOrder->id, $report->id, $payload);

        $this->assertSame('Diagnosis diperbarui.', $updated->diagnosis);
        $this->assertNull($updated->recommendation);
        $this->assertSame($before['action_taken'], $updated->action_taken);
        $this->assertSame($before['result'], $updated->result);
        $this->assertSame($technician->id, (int) $updated->created_by);
        $this->assertSame($workOrder->id, (int) $updated->work_order_id);
        $this->assertSame($scope->id, $updated->scope_item_id);
        $this->assertSame(1, $updated->revision_no);
        $this->assertNull($updated->previous_revision_id);
        $this->assertNull($updated->submitted_at);
        $this->assertNull($updated->reviewed_by);
        $this->assertNull($updated->review_notes);
        $this->assertNull($updated->usage_snapshot);
        $this->assertSame('draft', $updated->review_status);
        $this->assertSame(1, $updated->lock_version);
        $this->assertSame($version, $workOrder->fresh()->lock_version);
        $history = OperationHistory::query()->where('action', 'report.draft.update')->sole();
        $this->assertSame(['diagnosis', 'recommendation'], $history->facts['changed_fields']);
        $this->assertSame($report->id, (int) $history->subject_id);
        $this->assertSame($scope->id, $history->facts['scope_item_id']);
    }

    public function test_update_requires_content_and_valid_version(): void
    {
        [$technician, $workOrder, $scope] = $this->context();
        $service = app(ServiceReportDraftService::class);
        $report = $service->createDraft($technician, $workOrder->id, $scope->id, $this->payload($workOrder));

        foreach ([[], ['expected_report_version' => -1], ['diagnosis' => str_repeat('a', 10001)]] as $changes) {
            $input = array_replace(['command_key' => (string) Str::uuid(), 'expected_report_version' => 0], $changes);
            try {
                $service->updateDraft($technician, $workOrder->id, $report->id, $input);
                $this->fail('Invalid update must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertSame(422, $exception->status);
            }
        }
        $this->assertSame(0, $report->fresh()->lock_version);
        $this->assertDatabaseMissing('operation_history', ['action' => 'report.draft.update']);
    }

    public function test_update_retries_safely_but_rejects_reused_key_and_stale_version(): void
    {
        [$technician, $workOrder, $scope] = $this->context();
        $service = app(ServiceReportDraftService::class);
        $report = $service->createDraft($technician, $workOrder->id, $scope->id, $this->payload($workOrder));
        $payload = $this->updatePayload($report);
        $updated = $service->updateDraft($technician, $workOrder->id, $report->id, $payload);
        $savedAt = $updated->getRawOriginal('updated_at');
        $this->travel(2)->minutes();
        $workOrder->forceFill(['status' => 'on_hold'])->save();

        $retry = $service->updateDraft($technician, $workOrder->id, $report->id, $payload);

        $this->assertSame(1, $retry->lock_version);
        $this->assertSame($savedAt, $retry->getRawOriginal('updated_at'));
        $this->assertConflict(fn () => $service->updateDraft($technician, $workOrder->id, $report->id,
            array_replace($payload, ['diagnosis' => 'Different'])), 'command_key', 'COMMAND_KEY_REUSED');
        $this->assertConflict(fn () => $service->updateDraft($technician, $workOrder->id, $report->id,
            array_replace($payload, ['command_key' => (string) Str::uuid()])), 'expected_report_version', 'REPORT_VERSION_CONFLICT');
        $this->assertSame(1, OperationHistory::query()->where('action', 'report.draft.update')->count());
        $this->assertSame('Diagnosis baru.', $report->fresh()->diagnosis);
    }

    #[DataProvider('unauthorizedActors')]
    public function test_ineligible_actor_cannot_update_draft(array $attributes, bool $assigned): void
    {
        [$author, $workOrder, $scope] = $this->context();
        $report = app(ServiceReportDraftService::class)->createDraft($author, $workOrder->id, $scope->id, $this->payload($workOrder));
        $actor = User::factory()->create($attributes);
        if ($assigned) {
            $workOrder->technicians()->attach($actor, ['assigned_at' => now()]);
        }
        $this->expectException(AuthorizationException::class);
        try {
            app(ServiceReportDraftService::class)->updateDraft($actor, $workOrder->id, $report->id, $this->updatePayload($report));
        } finally {
            $this->assertSame(0, $report->fresh()->lock_version);
            $this->assertDatabaseMissing('operation_history', ['action' => 'report.draft.update']);
        }
    }

    public function test_another_assigned_technician_cannot_update_authors_draft(): void
    {
        [$author, $workOrder, $scope] = $this->context();
        $report = app(ServiceReportDraftService::class)->createDraft($author, $workOrder->id, $scope->id, $this->payload($workOrder));
        $other = User::factory()->create(['role' => 'teknisi']);
        $workOrder->technicians()->attach($other, ['assigned_at' => now()]);

        $this->expectException(AuthorizationException::class);
        try {
            app(ServiceReportDraftService::class)->updateDraft($other, $workOrder->id, $report->id, $this->updatePayload($report));
        } finally {
            $this->assertSame(0, $report->fresh()->lock_version);
        }
    }

    public function test_report_from_another_work_order_is_not_found_even_for_member_of_both(): void
    {
        [$author, $workOrder, $scope] = $this->context();
        [, $otherWorkOrder] = $this->context();
        $otherWorkOrder->technicians()->attach($author, ['assigned_at' => now()]);
        $report = app(ServiceReportDraftService::class)->createDraft($author, $workOrder->id, $scope->id, $this->payload($workOrder));
        $this->expectException(ModelNotFoundException::class);
        try {
            app(ServiceReportDraftService::class)->updateDraft($author, $otherWorkOrder->id, $report->id, $this->updatePayload($report));
        } finally {
            $this->assertSame(0, $report->fresh()->lock_version);
        }
    }

    public function test_report_with_cross_work_order_scope_cannot_be_updated(): void
    {
        [$author, $workOrder, $scope] = $this->context();
        [, , $otherScope] = $this->context();
        $report = app(ServiceReportDraftService::class)->createDraft($author, $workOrder->id, $scope->id, $this->payload($workOrder));
        $report->forceFill(['scope_item_id' => $otherScope->id])->save();

        $this->assertConflict(fn () => app(ServiceReportDraftService::class)->updateDraft(
            $author, $workOrder->id, $report->id, $this->updatePayload($report)), 'report_id', 'REPORT_M1_BASELINE_REQUIRED');
        $this->assertSame(0, $report->fresh()->lock_version);
    }

    public function test_older_draft_cannot_be_edited_after_a_successor_exists(): void
    {
        [$author, $workOrder, $scope] = $this->context();
        $report = app(ServiceReportDraftService::class)->createDraft($author, $workOrder->id, $scope->id, $this->payload($workOrder));
        $successor = $report->replicate();
        $successor->forceFill(['revision_no' => 2, 'previous_revision_id' => $report->id])->save();

        $this->assertConflict(fn () => app(ServiceReportDraftService::class)->updateDraft(
            $author, $workOrder->id, $report->id, $this->updatePayload($report)), 'report_id', 'REPORT_REVISION_CONFLICT');
        $this->assertSame(0, $report->fresh()->lock_version);
        $this->assertSame(0, $successor->fresh()->lock_version);
    }

    #[DataProvider('frozenReports')]
    public function test_non_draft_or_reviewed_report_is_not_editable(array $attributes): void
    {
        [$author, $workOrder, $scope] = $this->context();
        $report = app(ServiceReportDraftService::class)->createDraft($author, $workOrder->id, $scope->id, $this->payload($workOrder));
        $report->forceFill($attributes)->save();
        $before = $report->fresh()->getAttributes();

        $this->assertConflict(fn () => app(ServiceReportDraftService::class)->updateDraft(
            $author, $workOrder->id, $report->id, $this->updatePayload($report)), 'report_id', 'REPORT_STATE_CONFLICT');
        $this->assertSame($before, $report->fresh()->getAttributes());
    }

    public static function frozenReports(): array
    {
        return [
            'submitted' => [['review_status' => 'submitted']],
            'approved' => [['review_status' => 'approved']],
            'needs revision' => [['review_status' => 'needs_revision']],
            'submitted timestamp' => [['submitted_at' => '2026-11-15 01:00:00']],
            'review timestamp' => [['reviewed_at' => '2026-11-15 01:00:00']],
            'review note' => [['review_notes' => 'Already reviewed']],
        ];
    }

    #[DataProvider('invalidWorkOrders')]
    public function test_create_and_update_reject_ineligible_work_order_or_scope(array $woChanges, array $scopeChanges, string $field, string $code): void
    {
        [$author, $workOrder, $scope] = $this->context();
        $service = app(ServiceReportDraftService::class);
        $report = $service->createDraft($author, $workOrder->id, $scope->id, $this->payload($workOrder));
        $workOrder->forceFill($woChanges)->save();
        $scope->forceFill($scopeChanges)->save();

        $this->assertConflict(fn () => $service->createDraft($author, $workOrder->id, $scope->id, $this->payload($workOrder)), $field, $code);
        $this->assertConflict(fn () => $service->updateDraft($author, $workOrder->id, $report->id, $this->updatePayload($report)), $field, $code);
        $this->assertSame(0, $report->fresh()->lock_version);
        $this->assertDatabaseMissing('operation_history', ['action' => 'report.draft.update']);
    }

    public static function invalidWorkOrders(): array
    {
        $cases = [];
        foreach (['pending', 'assigned', 'on_hold', 'awaiting_acceptance', 'closed', 'cancelled'] as $status) {
            $cases[$status] = [['status' => $status], [], 'work_order_id', 'WORK_ORDER_STATE_CONFLICT'];
        }
        $cases['legacy'] = [['origin_kind' => null], [], 'work_order_id', 'WORK_ORDER_M1_BASELINE_REQUIRED'];
        $cases['no context'] = [['acceptance_context_ref' => null], [], 'work_order_id', 'WORK_ORDER_M1_BASELINE_REQUIRED'];
        $cases['no required scope'] = [[], ['is_required' => false], 'work_order_id', 'WORK_ORDER_M1_BASELINE_REQUIRED'];

        return $cases;
    }

    public function test_inactive_optional_scope_is_rejected_while_required_scope_remains_active(): void
    {
        [$author, $workOrder, $scope] = $this->context();
        $optional = $scope->replicate();
        $optional->forceFill(['scope_no' => 2, 'is_required' => false, 'created_at' => now()])->save();
        $service = app(ServiceReportDraftService::class);
        $report = $service->createDraft($author, $workOrder->id, $optional->id, $this->payload($workOrder));
        $optional->forceFill(['disposition' => 'retired'])->save();
        $this->assertConflict(fn () => $service->createDraft($author, $workOrder->id, $optional->id, $this->payload($workOrder)), 'scope_item_id', 'SCOPE_STATE_CONFLICT');
        $this->assertConflict(fn () => $service->updateDraft($author, $workOrder->id, $report->id, $this->updatePayload($report)), 'scope_item_id', 'SCOPE_STATE_CONFLICT');
    }

    #[DataProvider('revocations')]
    public function test_create_and_update_replay_recheck_current_account_and_membership(array $attributes, bool $removeMembership): void
    {
        [$author, $workOrder, $scope] = $this->context();
        $service = app(ServiceReportDraftService::class);
        $create = $this->payload($workOrder);
        $report = $service->createDraft($author, $workOrder->id, $scope->id, $create);
        $update = $this->updatePayload($report);
        $service->updateDraft($author, $workOrder->id, $report->id, $update);
        $before = OperationHistory::query()->count();
        $author->forceFill($attributes)->save();
        if ($removeMembership) {
            $workOrder->technicians()->detach($author);
        }
        foreach (['create', 'update'] as $operation) {
            try {
                if ($operation === 'create') {
                    $service->createDraft($author, $workOrder->id, $scope->id, $create);
                } else {
                    $service->updateDraft($author, $workOrder->id, $report->id, $update);
                }
                $this->fail('Revoked replay must fail.');
            } catch (AuthorizationException) {
                $this->assertSame($before, OperationHistory::query()->count());
                $this->assertSame(1, $report->fresh()->lock_version);
            }
        }
    }

    public static function revocations(): array
    {
        return [
            'disabled' => [['is_active' => false], false],
            'pending' => [['approval_status' => 'pending'], false],
            'rejected' => [['approval_status' => 'rejected'], false],
            'role changed' => [['role' => 'admin'], false],
            'membership removed' => [[], true],
        ];
    }

    #[DataProvider('draftOperations')]
    public function test_history_failure_rolls_back_domain_write_and_allows_same_key_retry(string $operation): void
    {
        [$author, $workOrder, $scope] = $this->context();
        $service = app(ServiceReportDraftService::class);
        $report = $operation === 'update' ? $service->createDraft($author, $workOrder->id, $scope->id, $this->payload($workOrder)) : null;
        $payload = $report ? $this->updatePayload($report) : $this->payload($workOrder);
        $before = ServiceReport::query()->get()->toArray();
        $historyCount = OperationHistory::query()->count();
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failed): void {
            if (! $failed && str_starts_with($sql, 'insert into `operation_history`')) {
                $failed = true;
                throw new RuntimeException('Report history failure');
            }
        });
        $run = fn () => $report
            ? $service->updateDraft($author, $workOrder->id, $report->id, $payload)
            : $service->createDraft($author, $workOrder->id, $scope->id, $payload);
        try {
            $run();
            $this->fail('History failure must abort the command.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Report history failure', $exception->getMessage());
        }
        $this->assertTrue($failed);
        $this->assertSame($before, ServiceReport::query()->get()->toArray());
        $this->assertSame($historyCount, OperationHistory::query()->count());
        $retry = $run();
        $this->assertSame($report ? 1 : 0, $retry->lock_version);
        $this->assertSame($historyCount + 1, OperationHistory::query()->count());
    }

    public static function draftOperations(): array
    {
        return ['create' => ['create'], 'update' => ['update']];
    }

    private function updatePayload(ServiceReport $report, array $overrides = []): array
    {
        return array_replace([
            'command_key' => (string) Str::uuid(),
            'expected_report_version' => $report->lock_version,
            'diagnosis' => 'Diagnosis baru.',
        ], $overrides);
    }

    public static function unauthorizedActors(): array
    {
        return [
            'teknisi bukan anggota' => [
                ['role' => 'teknisi'],
                false,
            ],
            'admin' => [
                ['role' => 'admin'],
                false,
            ],
            'koordinator' => [
                ['role' => 'koordinator'],
                false,
            ],
            'teknisi anggota nonaktif' => [
                ['role' => 'teknisi', 'is_active' => false],
                true,
            ],
            'teknisi anggota belum approved' => [
                ['role' => 'teknisi', 'approval_status' => 'pending'],
                true,
            ],
        ];
    }

    private function assertConflict(
        callable $operation,
        string $field,
        string $code,
    ): void {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertSame([$code], $exception->errors()[$field]);

            return;
        }

        $this->fail('Operasi seharusnya menghasilkan conflict.');
    }

    private function payload(
        WorkOrder $workOrder,
        array $overrides = [],
    ): array {
        return array_replace([
            'command_key' => (string) Str::uuid(),
            'expected_work_order_version' => (int) $workOrder->lock_version,
            'diagnosis' => 'Kabel jaringan terlepas.',
            'action_taken' => 'Memasang kembali kabel jaringan.',
            'result' => 'Live feed kembali tampil.',
            'recommendation' => 'Periksa konektor secara berkala.',
        ], $overrides);
    }

    /** @return array{User, WorkOrder, WorkOrderScopeItem} */
    private function context(): array
    {
        $coordinator = User::factory()->create([
            'role' => 'koordinator',
        ]);

        $technician = User::factory()->create([
            'role' => 'teknisi',
        ]);

        $customer = Customer::query()->create([
            'name' => 'Customer Draft Report',
        ]);

        $site = $customer->sites()->create([
            'site_name' => 'Site Draft Report',
            'address' => 'Bekasi',
        ]);

        $workOrder = app(CreateWorkOrderService::class)->create(
            $coordinator,
            [
                'command_key' => (string) Str::uuid(),
                'origin_kind' => 'manual',
                'manual_reason' => 'Pengujian draft laporan M1.',
                'initial_scope' => [
                    'description' => 'Pulihkan kamera CCTV.',
                    'acceptance_criteria' => 'Live feed tampil.',
                ],
                'customer_site_id' => $site->id,
                'title' => 'Pengujian draft laporan',
                'description' => 'Kamera offline.',
                'service_type' => 'maintenance',
                'category' => 'cctv',
                'priority' => 'medium',
                'asset_ids' => [],
            ],
        );

        $workOrder->technicians()->attach($technician, [
            'assigned_at' => now(),
        ]);

        // Fixture menyiapkan state eksekusi agar tes fokus pada draft report.
        $workOrder->forceFill([
            'status' => 'in_progress',
            'lock_version' => (int) $workOrder->lock_version + 1,
        ])->save();

        return [
            $technician,
            $workOrder->refresh(),
            $workOrder->scopeItems()->sole(),
        ];
    }
}
