<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkVisit;
use App\Services\CreateWorkOrderService;
use App\Services\VisitSchedulingService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class VisitSchedulingDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_stores_utc_and_audit_without_reserving_a_slot(): void
    {
        [$coordinator, $workOrder] = $this->context();

        $visit = app(VisitSchedulingService::class)->createDraft(
            $coordinator,
            $workOrder->id,
            [
                ...$this->payload(),
                'status' => 'confirmed',
                'visit_no' => 99,
            ],
        );

        $visit->refresh();

        $this->assertSame('draft', $visit->status);
        $this->assertSame(1, $visit->visit_no);
        $this->assertSame(0, $visit->lock_version);
        $this->assertSame($coordinator->id, $visit->created_by);
        $this->assertSame(
            '2026-11-12 02:00:00',
            $visit->starts_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame(
            '2026-11-12 04:00:00',
            $visit->ends_at->format('Y-m-d H:i:s'),
        );
        $this->assertSame('Asia/Jakarta', $visit->zone_name);
        $this->assertNull($visit->previous_visit_id);

        $this->assertDatabaseCount('work_visits', 1);
        $this->assertDatabaseCount('visit_bookings', 0);
        $this->assertDatabaseCount('work_order_technicians', 0);
        $this->assertSame('pending', $workOrder->fresh()->status);
        $this->assertNull($workOrder->fresh()->scheduled_at);

        $history = OperationHistory::query()
            ->where('action', 'visit.create_draft')
            ->sole();

        $this->assertSame('work_visit', $history->subject_type);
        $this->assertSame($visit->id, $history->subject_id);
        $this->assertSame($visit->id, $history->facts['visit_id']);
        $this->assertSame(
            $workOrder->id,
            $history->facts['work_order_id'],
        );
    }

    public function test_replay_returns_existing_visit_even_after_wo_state_changes(): void
    {
        [$coordinator, $workOrder] = $this->context();
        $payload = $this->payload();
        $service = app(VisitSchedulingService::class);

        $first = $service->createDraft(
            $coordinator,
            $workOrder->id,
            $payload,
        );

        $workOrder->forceFill(['status' => 'completed'])->save();

        $replay = $service->createDraft(
            $coordinator,
            $workOrder->id,
            $payload,
        );

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('work_visits', 1);
        $this->assertSame(
            1,
            OperationHistory::query()
                ->where('action', 'visit.create_draft')
                ->count(),
        );
        $this->assertSame('completed', $workOrder->fresh()->status);
    }

    public function test_new_key_creates_next_visit_number_without_booking(): void
    {
        [$coordinator, $workOrder] = $this->context();
        $service = app(VisitSchedulingService::class);

        $first = $service->createDraft(
            $coordinator,
            $workOrder->id,
            $this->payload(),
        );

        $second = $service->createDraft(
            $coordinator,
            $workOrder->id,
            $this->payload(),
        );

        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(1, $first->visit_no);
        $this->assertSame(2, $second->visit_no);
        $this->assertDatabaseCount('work_visits', 2);
        $this->assertDatabaseCount('visit_bookings', 0);
    }

    public function test_same_key_with_changed_payload_is_rejected(): void
    {
        [$coordinator, $workOrder] = $this->context();
        $payload = $this->payload();
        $service = app(VisitSchedulingService::class);

        $first = $service->createDraft(
            $coordinator,
            $workOrder->id,
            $payload,
        );

        try {
            $service->createDraft(
                $coordinator,
                $workOrder->id,
                [...$payload, 'reason' => 'Alasan berbeda'],
            );

            $this->fail('Changed payload must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertSame(409, $exception->status);
            $this->assertSame(
                ['COMMAND_KEY_REUSED'],
                $exception->errors()['command_key'],
            );
        }

        $this->assertDatabaseCount('work_visits', 1);
        $this->assertSame(
            $payload['reason'],
            $first->fresh()->reason,
        );
    }

    #[DataProvider('ineligibleActors')]
    public function test_current_account_is_checked_even_with_stale_actor_model(
        array $attributes,
    ): void {
        [$coordinator, $workOrder] = $this->context();

        DB::table('users')
            ->where('id', $coordinator->id)
            ->update($attributes);

        $this->expectException(AuthorizationException::class);

        app(VisitSchedulingService::class)->createDraft(
            $coordinator,
            $workOrder->id,
            $this->payload(),
        );
    }

    public static function ineligibleActors(): array
    {
        return [
            'admin' => [['role' => 'admin']],
            'technician' => [['role' => 'teknisi']],
            'missing role' => [['role' => null]],
            'inactive' => [['is_active' => false]],
            'pending' => [['approval_status' => 'pending']],
            'rejected' => [['approval_status' => 'rejected']],
        ];
    }

    public function test_timezone_offset_mismatch_is_rejected(): void
    {
        [$coordinator, $workOrder] = $this->context();

        try {
            app(VisitSchedulingService::class)->createDraft(
                $coordinator,
                $workOrder->id,
                [
                    ...$this->payload(),
                    'starts_at' => '2026-11-12T09:00+08:00',
                ],
            );

            $this->fail('Timezone offset mismatch must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey(
                'starts_at',
                $exception->errors(),
            );
        }

        $this->assertDatabaseCount('work_visits', 0);
        $this->assertSame(
            0,
            OperationHistory::query()
                ->where('action', 'visit.create_draft')
                ->count(),
        );
    }

    public function test_audit_failure_rolls_back_visit_and_allows_same_key_retry(): void
    {
        [$coordinator, $workOrder] = $this->context();
        $payload = $this->payload();
        $historyCount = OperationHistory::query()->count();
        $failed = false;

        DB::connection()->beforeExecuting(
            function (string $sql) use (&$failed): void {
                if (!$failed
                    && str_starts_with(
                        $sql,
                        'insert into `operation_history`',
                    )) {
                    $failed = true;

                    throw new RuntimeException('Simulated audit failure.');
                }
            },
        );

        $service = app(VisitSchedulingService::class);

        try {
            $service->createDraft(
                $coordinator,
                $workOrder->id,
                $payload,
            );

            $this->fail('Audit failure must abort the command.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated audit failure.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue($failed);
        $this->assertDatabaseCount('work_visits', 0);
        $this->assertDatabaseCount('visit_bookings', 0);
        $this->assertDatabaseCount('operation_history', $historyCount);

        $visit = $service->createDraft(
            $coordinator,
            $workOrder->id,
            $payload,
        );

        $this->assertSame(1, $visit->visit_no);
        $this->assertDatabaseCount('work_visits', 1);
        $this->assertDatabaseCount(
            'operation_history',
            $historyCount + 1,
        );
    }

    /** @return array{User, WorkOrder} */
    private function context(): array
    {
        $coordinator = User::factory()->create([
            'role' => 'koordinator',
        ]);

        $customer = Customer::query()->create([
            'name' => 'PT Scheduling Testing',
        ]);

        $site = $customer->sites()->create([
            'site_name' => 'Office',
            'address' => 'Bekasi',
        ]);

        $workOrder = app(CreateWorkOrderService::class)->create(
            $coordinator,
            [
                'command_key' => (string) Str::uuid(),
                'origin_kind' => 'manual',
                'manual_reason' => 'Permintaan operasional pengujian',
                'initial_scope' => [
                    'description' => 'Pulihkan tampilan kamera',
                    'acceptance_criteria' => 'Live feed tampil pada NVR',
                ],
                'customer_site_id' => $site->id,
                'title' => 'Pemeriksaan kamera',
                'description' => 'Kamera tidak menampilkan gambar',
                'service_type' => 'maintenance',
                'category' => 'cctv',
                'priority' => 'medium',
                'asset_ids' => [],
            ],
        );

        return [$coordinator, $workOrder];
    }

    private function payload(): array
    {
        return [
            'command_key' => (string) Str::uuid(),
            'starts_at' => '2026-11-12T09:00+07:00',
            'ends_at' => '2026-11-12T11:00+07:00',
            'zone_name' => 'Asia/Jakarta',
            'reason' => 'Rencana pemeriksaan kamera',
        ];
    }
}