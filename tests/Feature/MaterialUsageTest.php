<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\MaterialUsage;
use App\Models\OperationHistory;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\CreateWorkOrderService;
use App\Services\MaterialUsageService;
use App\Services\ServiceReportDraftService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MaterialUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_consume_return_and_reversal_are_an_audited_decimal_ledger_with_safe_retries(): void
    {
        [$actor, $wo, $scope, $report] = $this->draft();
        $service = app(MaterialUsageService::class);
        $input = $this->input($report, ['quantity' => '1.125']);
        $consume = $service->record($actor, $wo->id, $report->id, $input);
        $retry = $service->record($actor, $wo->id, $report->id, $input);
        $this->assertSame($consume->id, $retry->id);
        $this->assertSame('1.125', $consume->quantity);
        $this->assertSame($scope->id, (int) $consume->scope_item_id);
        $return = $service->record($actor, $wo->id, $report->id, $this->input($report->fresh(), [
            'movement_kind' => 'return', 'adjusts_usage_id' => $consume->id, 'quantity' => '0.125', 'reason' => 'Sisa dikembalikan',
        ]));
        $reverse = $service->record($actor, $wo->id, $report->id, $this->input($report->fresh(), [
            'movement_kind' => 'reversal', 'adjusts_usage_id' => $consume->id, 'quantity' => '1', 'reason' => 'Koreksi pencatatan',
        ]));
        $this->assertSame('0.125', $return->quantity);
        $this->assertSame('1.000', $reverse->quantity);
        $this->assertSame(3, $report->fresh()->lock_version);
        $this->assertDatabaseCount('material_usages', 3);
        $this->assertSame(3, OperationHistory::query()->where('action', 'material.record')->count());
        $this->assertConflict(fn () => $service->record($actor, $wo->id, $report->id, $this->input($report->fresh(), [
            'movement_kind' => 'return', 'adjusts_usage_id' => $consume->id, 'quantity' => '0.001', 'reason' => 'Exceeds remaining',
        ])), 'MATERIAL_RETURN_EXCEEDS_USAGE');
        $this->assertSame('in_progress', $wo->fresh()->status);
        $this->assertSame(3, $report->fresh()->lock_version);
    }

    public function test_stale_coordinator_object_cannot_bypass_membership_even_on_replay(): void
    {
        [$member, $wo, , $report] = $this->draft();
        $actor = User::factory()->create(['role' => 'koordinator']);
        User::query()->whereKey($actor->id)->update(['role' => 'teknisi']);
        $input = $this->input($report);
        $service = app(MaterialUsageService::class);
        try {
            $service->record($actor, $wo->id, $report->id, $input);
            $this->fail('Stale actor must not bypass membership.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('material_usages', 0);
        }
        $wo->technicians()->attach($actor, ['assigned_at' => now()]);
        $usage = $service->record($actor, $wo->id, $report->id, $input);
        $wo->technicians()->detach($actor);
        $this->expectException(AuthorizationException::class);
        $service->record($actor, $wo->id, $report->id, $input);
    }

    #[DataProvider('invalidQuantities')]
    public function test_invalid_quantities_do_not_create_movements(string $quantity): void
    {
        [$actor, $wo, , $report] = $this->draft();
        try {
            app(MaterialUsageService::class)->record($actor, $wo->id, $report->id, $this->input($report, ['quantity' => $quantity]));
            $this->fail('Invalid quantity must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('quantity', $exception->errors());
        }
        $this->assertDatabaseCount('material_usages', 0);
        $this->assertSame(0, $report->fresh()->lock_version);
    }

    public static function invalidQuantities(): array
    {
        return ['zero' => ['0'], 'negative' => ['-1'], 'precision' => ['0.0001'], 'scientific' => ['1e3'], 'overflow' => ['1000000000000']];
    }

    public function test_adjustments_require_matching_consume_parent_unit_and_scope(): void
    {
        [$actor, $wo, $scope, $report] = $this->draft();
        $parent = MaterialUsage::factory()->create(['scope_item_id' => $scope->id, 'recorded_by' => $actor->id]);
        $service = app(MaterialUsageService::class);
        $input = $this->input($report, ['movement_kind' => 'return', 'adjusts_usage_id' => $parent->id, 'reason' => 'Return', 'unit_code' => 'piece']);
        $this->assertConflict(fn () => $service->record($actor, $wo->id, $report->id, $input), 'MATERIAL_PARENT_MISMATCH');
        $this->assertSame(0, $report->fresh()->lock_version);
        $this->assertDatabaseCount('material_usages', 1);
    }

    public function test_movements_cannot_be_updated_or_deleted_by_another_writer(): void
    {
        [$actor, , $scope] = $this->draft();
        $movement = MaterialUsage::factory()->create(['scope_item_id' => $scope->id, 'recorded_by' => $actor->id]);
        $this->assertImmutable(fn () => DB::table('material_usages')->where('id', $movement->id)->update(['quantity' => '2.000']), 'MATERIAL_MOVEMENT_IMMUTABLE');
        $this->assertImmutable(fn () => $movement->delete(), 'MATERIAL_MOVEMENT_IMMUTABLE');
        $this->assertSame('1.000', $movement->fresh()->quantity);
    }

    public function test_history_failure_rolls_back_movement_and_report_version(): void
    {
        [$actor, $wo, , $report] = $this->draft();
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failed): void {
            if (! $failed && str_starts_with($sql, 'insert into `operation_history`')) {
                $failed = true;
                throw new RuntimeException('Material audit failure');
            }
        });
        $input = $this->input($report);
        try {
            app(MaterialUsageService::class)->record($actor, $wo->id, $report->id, $input);
            $this->fail('Audit failure must roll back material.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Material audit failure', $exception->getMessage());
        }
        $this->assertSame(0, $report->fresh()->lock_version);
        $this->assertDatabaseCount('material_usages', 0);
        app(MaterialUsageService::class)->record($actor, $wo->id, $report->id, $input);
        $this->assertSame(1, $report->fresh()->lock_version);
    }

    public function test_submitted_scope_requires_a_new_revision_before_material_changes(): void
    {
        [$actor, $wo, , $report] = $this->draft();
        $report->forceFill(['submitted_at' => now(), 'review_status' => 'submitted'])->save();
        $this->assertConflict(fn () => app(MaterialUsageService::class)->record($actor, $wo->id, $report->id, $this->input($report)), 'REPORT_STATE_CONFLICT');
        $this->assertDatabaseCount('material_usages', 0);
    }

    private function input(ServiceReport $report, array $overrides = []): array
    {
        return array_replace([
            'command_key' => (string) Str::uuid(), 'expected_report_version' => $report->lock_version,
            'item_description' => 'Kabel jaringan', 'unit_code' => 'meter', 'quantity' => '1.000',
            'movement_kind' => 'consume', 'supply_source' => 'company', 'disposition' => 'covered',
        ], $overrides);
    }

    private function draft(): array
    {
        [$actor, $wo, $scope] = $this->context();
        $report = app(ServiceReportDraftService::class)->createDraft($actor, $wo->id, $scope->id, [
            'command_key' => (string) Str::uuid(), 'expected_work_order_version' => (int) $wo->lock_version,
        ]);

        return [$actor, $wo, $scope, $report];
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
        $this->fail('Expected a domain conflict.');
    }

    private function assertImmutable(callable $operation, string $message): void
    {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertSame('45000', $exception->errorInfo[0]);
            $this->assertStringContainsString($message, $exception->getMessage());

            return;
        }
        $this->fail('Expected database immutability guard.');
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
