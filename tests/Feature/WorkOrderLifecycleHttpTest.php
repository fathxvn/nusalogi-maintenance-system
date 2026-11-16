<?php

namespace Tests\Feature;

use App\Models\BastItem;
use App\Models\BastVersion;
use App\Models\Customer;
use App\Models\PrivateEvidenceFile;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\BastService;
use App\Services\CreateWorkOrderService;
use App\Services\ServiceReportDraftService;
use App\Services\ServiceReportWorkflowService;
use App\Services\VisitBookingExecutionService;
use App\Services\VisitSchedulingService;
use App\Services\WorkOrderStatusService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkOrderLifecycleHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_lifecycle_page_exposes_only_allowed_actions_and_keeps_legacy_readonly(): void
    {
        $this->withoutVite();
        [$tech, $wo] = $this->context();
        $this->actingAs($tech)->get(route('work-orders.lifecycle.show', $wo))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('work-orders/lifecycle')->where('actions.close', null)->where('actions.cancel', null)
            ->where('actions.hold.url', route('work-orders.lifecycle.change', [$wo, 'hold']))->has('scopes', 1)->etc());
        $outsider = User::factory()->create(['role' => 'teknisi']);
        $this->actingAs($outsider)->get(route('work-orders.lifecycle.show', $wo))->assertNotFound();
        $this->actingAs($outsider)->postJson(route('work-orders.lifecycle.change', [$wo, 'hold']), $this->woCommand($wo, ['reason' => 'No access']))->assertNotFound();
        $wo->forceFill(['origin_kind' => 'legacy_unknown'])->save();
        $this->actingAs($tech)->get(route('work-orders.lifecycle.show', $wo))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('actions.hold', null)->where('unavailableReason', fn ($reason): bool => filled($reason))->etc());
    }

    public function test_http_hold_uses_expected_version_and_coordinator_only_close(): void
    {
        $this->withoutVite();
        [$tech, $wo] = $this->context();
        $input = $this->woCommand($wo, ['reason' => 'Site inaccessible']);
        $this->actingAs($tech)->post(route('work-orders.lifecycle.change', [$wo, 'hold']), $input)
            ->assertRedirectToRoute('work-orders.lifecycle.show', $wo)->assertSessionHasNoErrors();
        $this->actingAs($tech)->postJson(route('work-orders.lifecycle.change', [$wo, 'resume']), [...$input, 'command_key' => (string) Str::uuid()])
            ->assertConflict()->assertJsonPath('errors.expected_work_order_version.0', 'WORK_ORDER_VERSION_CONFLICT');
        $this->actingAs($tech)->postJson(route('work-orders.lifecycle.close', $wo), $input)->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson(route('work-orders.lifecycle.close', $wo), $input)->assertForbidden();
    }

    public function test_http_close_checks_full_acceptance_and_reports_closed_proof(): void
    {
        $this->withoutVite();
        Storage::fake('evidence');
        [$actor, , $wo, $report] = $this->approved();
        $this->actingAs($actor)->postJson(route('work-orders.lifecycle.close', $wo), $this->woCommand($wo))
            ->assertConflict()->assertJsonPath('errors.work_order_id.0', 'ALL_REQUIRED_SCOPES_MUST_BE_ACCEPTED');
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report])));
        app(BastService::class)->decide($actor, $version->id, $this->decision($actor, $version));
        $this->actingAs($actor)->post(route('work-orders.lifecycle.close', $wo), $this->woCommand($wo->fresh()))
            ->assertRedirectToRoute('work-orders.lifecycle.show', $wo)->assertSessionHasNoErrors();
        $this->actingAs($actor)->get(route('work-orders.lifecycle.show', $wo))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('workOrder.status', 'closed')->where('workOrder.closed_at', fn ($value): bool => filled($value))
            ->has('closureEvidence', 1)->where('actions.close', null)->etc());
    }

    public function test_m1_start_cannot_bypass_booking_and_actual_started_at_survives_rework(): void
    {
        [$tech, $wo] = $this->context();
        $actor = User::factory()->create(['role' => 'koordinator']);
        $wo->forceFill(['status' => 'assigned'])->save();
        try {
            app(WorkOrderStatusService::class)->changeStatus($wo->id, $tech, 'in_progress');
            $this->fail('M1 execution must use a versioned booking.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }
        $this->assertSame('assigned', $wo->fresh()->status);
        $visit = app(VisitSchedulingService::class)->createDraft($actor, $wo->id, ['command_key' => (string) Str::uuid(),
            'starts_at' => '2026-11-20T09:00+07:00', 'ends_at' => '2026-11-20T11:00+07:00', 'zone_name' => 'Asia/Jakarta']);
        app(VisitSchedulingService::class)->confirm($actor, $visit->id, ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 0, 'technician_ids' => [$tech->id]]);
        $booking = $visit->bookings()->sole();
        $input = ['command_key' => (string) Str::uuid(), 'expected_visit_version' => 1, 'expected_booking_status' => 'confirmed'];
        $started = app(VisitBookingExecutionService::class)->start($tech, $booking->id, $input);
        $this->assertTrue($wo->fresh()->started_at->equalTo($started->actual_start_at));
        app(VisitBookingExecutionService::class)->start($tech, $booking->id, $input);
        $this->assertTrue($wo->fresh()->started_at->equalTo($started->actual_start_at));
    }

    private function woCommand(WorkOrder $wo, array $extra = []): array
    {
        return ['command_key' => (string) Str::uuid(), 'expected_work_order_version' => $wo->lock_version, ...$extra];
    }

    private function approved(?WorkOrder $shared = null): array
    {
        [$tech, $wo, $scope] = $this->context();
        if ($shared) {
            $wo->forceFill(['customer_site_id' => $shared->customer_site_id, 'acceptance_context_ref' => $shared->acceptance_context_ref])->save();
        }
        $report = app(ServiceReportDraftService::class)->createDraft($tech, $wo->id, $scope->id, $this->content($wo));
        $workflow = app(ServiceReportWorkflowService::class);
        $report = $workflow->submit($tech, $wo->id, $report->id, $this->command($report));
        $actor = User::factory()->create(['role' => 'koordinator']);
        $report = $workflow->review($actor, $wo->id, $report->id, [...$this->command($report), 'decision' => 'approved']);

        return [$actor, $tech, $wo->refresh(), $report];
    }

    private function package(array $reports): array
    {
        $wo = $reports[0]->workOrder->fresh();
        $versions = [];
        foreach ($reports as $report) {
            $versions[$report->work_order_id] = (int) $report->workOrder->fresh()->lock_version;
        }

        return ['command_key' => (string) Str::uuid(), 'customer_id' => $wo->site->customer_id,
            'acceptance_context_ref' => $wo->acceptance_context_ref, 'report_ids' => array_map(fn (ServiceReport $report): int => $report->id, $reports), 'work_order_versions' => $versions];
    }

    private function upload(User $actor, BastVersion $version, string $purpose): PrivateEvidenceFile
    {
        $file = UploadedFile::fake()->createWithContent($purpose.'.pdf', "%PDF-1.4\nTest signed or issued evidence\n%%EOF");

        return app(BastService::class)->upload($actor, $version->id, $file, ['command_key' => (string) Str::uuid(),
            'expected_version' => $version->lock_version, 'purpose' => $purpose, 'scope_confirmed' => true, 'sha256' => hash_file('sha256', $file->getRealPath())]);
    }

    private function signer(BastVersion $version): array
    {
        $sites = $version->items()->with('reportRevision.workOrder')->get()->map(fn (BastItem $item): int => (int) $item->reportRevision->workOrder->customer_site_id)->unique()->values()->all();

        return ['signer_name' => 'PIC Customer', 'signer_capacity' => 'PIC berwenang seluruh paket', 'authority_ref' => 'Surat kuasa QA fixture', 'authorized_site_ids' => $sites];
    }

    private function issue(User $actor, BastVersion $version): BastVersion
    {
        $file = $this->upload($actor, $version, 'issued');

        return app(BastService::class)->issue($actor, $version->id, [...$this->signer($version), 'command_key' => (string) Str::uuid(),
            'expected_version' => $version->lock_version, 'evidence_file_id' => $file->id, 'scope_confirmed' => true]);
    }

    private function decision(User $actor, BastVersion $version): array
    {
        $file = $this->upload($actor, $version, 'decision');

        return [...$this->signer($version), 'command_key' => (string) Str::uuid(), 'expected_version' => $version->lock_version,
            'decision' => 'accepted', 'evidence_file_id' => $file->id, 'decided_at' => now()->toISOString()];
    }

    private function command(ServiceReport $report, array $extra = []): array
    {
        return [...$extra, 'command_key' => (string) Str::uuid(), 'expected_report_version' => $report->lock_version,
            'expected_work_order_version' => (int) $report->workOrder->lock_version];
    }

    private function content(WorkOrder $wo): array
    {
        return ['command_key' => (string) Str::uuid(), 'expected_work_order_version' => (int) $wo->lock_version,
            'diagnosis' => 'Kabel terlepas', 'action_taken' => 'Pasang kembali', 'result' => 'Live feed normal',
            'checklist_snapshot' => [['code' => 'feed', 'label' => 'Live feed', 'outcome' => 'pass']]];
    }

    private function draft(): array
    {
        [$actor, $wo, $scope] = $this->context();
        $report = app(ServiceReportDraftService::class)->createDraft($actor, $wo->id, $scope->id, $this->content($wo));

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
