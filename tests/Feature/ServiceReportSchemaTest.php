<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\CreateWorkOrderService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ServiceReportSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_report_and_photo_remain_compatible(): void
    {
        [$actor, $workOrder] = $this->context();

        $this->assertTrue(Schema::hasColumns('service_reports', [
            'scope_item_id',
            'revision_no',
            'previous_revision_id',
            'checklist_snapshot',
            'usage_snapshot',
            'asset_results',
            'lock_version',
        ]));

        $report = ServiceReport::query()->create([
            'work_order_id' => $workOrder->id,
            'created_by' => $actor->id,
            'diagnosis' => 'Koneksi kamera terputus.',
            'action_taken' => 'Memperbaiki koneksi.',
            'result' => 'Live feed kembali tampil.',
            'recommendation' => 'Periksa kabel secara berkala.',
        ]);

        $photo = $report->photos()->create([
            'uploaded_by' => $actor->id,
            'phase' => 'after',
            'storage_path' => 'qa/schema/after.jpg',
            'caption' => 'Foto pengujian schema.',
        ]);

        $report->refresh();

        $this->assertNull($report->scope_item_id);
        $this->assertNull($report->revision_no);
        $this->assertNull($report->previous_revision_id);
        $this->assertNull($report->checklist_snapshot);
        $this->assertNull($report->usage_snapshot);
        $this->assertNull($report->asset_results);
        $this->assertSame(0, (int) $report->lock_version);
        $this->assertSame('draft', $report->review_status);
        $this->assertSame(
            $workOrder->id,
            $report->workOrder->id,
        );
        $this->assertSame(
            $report->id,
            $photo->serviceReport->id,
        );

        $this->assertDatabaseHas('service_photos', [
            'id' => $photo->id,
            'service_report_id' => $report->id,
            'storage_path' => 'qa/schema/after.jpg',
        ]);

        // Beberapa laporan legacy boleh sama-sama belum memiliki scope/revisi.
        $another = $this->insertReport($actor, $workOrder);

        $this->assertDatabaseHas('service_reports', [
            'id' => $another,
            'scope_item_id' => null,
            'revision_no' => null,
        ]);
    }

    public function test_revision_number_is_unique_within_each_scope(): void
    {
        [$actor, $workOrder, $scope] = $this->context();
        [$otherActor, $otherWorkOrder, $otherScope] = $this->context();

        $first = $this->insertReport($actor, $workOrder, [
            'scope_item_id' => $scope->id,
            'revision_no' => 1,
        ]);

        $other = $this->insertReport($otherActor, $otherWorkOrder, [
            'scope_item_id' => $otherScope->id,
            'revision_no' => 1,
        ]);

        $this->assertDatabaseHas('service_reports', [
            'id' => $first,
            'scope_item_id' => $scope->id,
            'revision_no' => 1,
        ]);

        $this->assertDatabaseHas('service_reports', [
            'id' => $other,
            'scope_item_id' => $otherScope->id,
            'revision_no' => 1,
        ]);

        $this->assertConstraintViolation(
            fn () => $this->insertReport($actor, $workOrder, [
                'scope_item_id' => $scope->id,
                'revision_no' => 1,
            ]),
            1062,
        );
    }

    public function test_a_revision_can_have_only_one_successor(): void
    {
        [$actor, $workOrder, $scope] = $this->context();

        $first = $this->insertReport($actor, $workOrder, [
            'scope_item_id' => $scope->id,
            'revision_no' => 1,
        ]);

        $second = $this->insertReport($actor, $workOrder, [
            'scope_item_id' => $scope->id,
            'revision_no' => 2,
            'previous_revision_id' => $first,
        ]);

        $this->assertDatabaseHas('service_reports', [
            'id' => $second,
            'previous_revision_id' => $first,
        ]);

        $this->assertConstraintViolation(
            fn () => $this->insertReport($actor, $workOrder, [
                'scope_item_id' => $scope->id,
                'revision_no' => 3,
                'previous_revision_id' => $first,
            ]),
            1062,
        );
    }

    public function test_report_rejects_a_missing_scope(): void
    {
        [$actor, $workOrder] = $this->context();

        $missingScope = (int) WorkOrderScopeItem::query()->max('id') + 1;

        $this->assertConstraintViolation(
            fn () => $this->insertReport($actor, $workOrder, [
                'scope_item_id' => $missingScope,
                'revision_no' => 1,
            ]),
            1452,
        );
    }

    public function test_report_rejects_a_missing_previous_revision(): void
    {
        [$actor, $workOrder, $scope] = $this->context();

        $this->assertConstraintViolation(
            fn () => $this->insertReport($actor, $workOrder, [
                'scope_item_id' => $scope->id,
                'revision_no' => 2,
                'previous_revision_id' => PHP_INT_MAX,
            ]),
            1452,
        );
    }

    public function test_referenced_scope_and_previous_revision_cannot_be_deleted(): void
    {
        [$actor, $workOrder, $scope] = $this->context();

        $first = $this->insertReport($actor, $workOrder, [
            'scope_item_id' => $scope->id,
            'revision_no' => 1,
        ]);

        $second = $this->insertReport($actor, $workOrder, [
            'scope_item_id' => $scope->id,
            'revision_no' => 2,
            'previous_revision_id' => $first,
        ]);

        $this->assertConstraintViolation(
            fn () => DB::table('work_order_scope_items')
                ->where('id', $scope->id)
                ->delete(),
            1451,
        );

        $this->assertConstraintViolation(
            fn () => DB::table('service_reports')
                ->where('id', $first)
                ->delete(),
            1644,
            '45000',
        );

        $this->assertDatabaseHas('work_order_scope_items', [
            'id' => $scope->id,
        ]);

        $this->assertDatabaseHas('service_reports', [
            'id' => $first,
        ]);

        $this->assertDatabaseHas('service_reports', [
            'id' => $second,
            'previous_revision_id' => $first,
        ]);
    }

    private function assertConstraintViolation(
        callable $operation,
        int $driverCode,
        string $sqlState = '23000',
    ): void {
        try {
            $operation();
        } catch (QueryException $exception) {
            $this->assertSame(
                $sqlState,
                $exception->errorInfo[0],
            );

            $this->assertSame(
                $driverCode,
                (int) $exception->errorInfo[1],
            );

            return;
        }

        $this->fail('Operasi seharusnya ditolak oleh constraint MySQL.');
    }

    private function insertReport(
        User $actor,
        WorkOrder $workOrder,
        array $attributes = [],
    ): int {
        return DB::table('service_reports')->insertGetId(
            array_replace([
                'work_order_id' => $workOrder->id,
                'created_by' => $actor->id,
                'diagnosis' => 'Diagnosis pengujian schema.',
                'created_at' => now(),
                'updated_at' => now(),
            ], $attributes),
        );
    }

    /** @return array{User, WorkOrder, WorkOrderScopeItem} */
    private function context(): array
    {
        $actor = User::factory()->create([
            'role' => 'koordinator',
        ]);

        $customer = Customer::query()->create([
            'name' => 'Customer Report Schema',
        ]);

        $site = $customer->sites()->create([
            'site_name' => 'Site Report Schema',
            'address' => 'Bekasi',
        ]);

        $workOrder = app(CreateWorkOrderService::class)->create(
            $actor,
            [
                'command_key' => (string) Str::uuid(),
                'origin_kind' => 'manual',
                'manual_reason' => 'Pengujian schema laporan M1.',
                'initial_scope' => [
                    'description' => 'Pulihkan kamera CCTV.',
                    'acceptance_criteria' => 'Live feed tampil.',
                ],
                'customer_site_id' => $site->id,
                'title' => 'Pengujian schema laporan',
                'description' => 'Kamera offline.',
                'service_type' => 'maintenance',
                'category' => 'cctv',
                'priority' => 'medium',
                'asset_ids' => [],
            ],
        );

        return [
            $actor,
            $workOrder,
            $workOrder->scopeItems()->sole(),
        ];
    }
}
