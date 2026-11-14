<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\CreateWorkOrderService;
use App\Services\ServiceReportDraftService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ServiceReportHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_technician_and_coordinator_can_complete_document_revision_cycle_via_http(): void
    {
        [$actor, $wo, , $report] = $this->draft();
        $show = route('work-orders.reports.show', [$wo, $report]);
        $this->actingAs($actor)->get($show)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('work-orders/reports/show')->where('report.is_head', true)
            ->where('actions.update.url', route('work-orders.reports.update', [$wo, $report]))->where('actions.review', null));
        $this->post(route('work-orders.reports.submit', [$wo, $report]), $this->command($report))->assertRedirect($show)->assertSessionHasNoErrors();
        $this->assertSame('submitted', $report->fresh()->review_status);
        $this->assertSame('pending_review', $wo->fresh()->status);
        $reviewer = User::factory()->create(['role' => 'koordinator']);
        $this->actingAs($reviewer)->get($show)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('actions.update', null)->where('actions.review.url', route('work-orders.reports.review', [$wo, $report])));
        $this->post(route('work-orders.reports.review', [$wo, $report]), $this->command($report->fresh(), [
            'decision' => 'needs_revision', 'category' => 'document_fix', 'reason' => 'Perjelas hasil',
        ]))->assertRedirect($show)->assertSessionHasNoErrors();
        $this->actingAs($actor)->post(route('work-orders.reports.revise', [$wo, $report]), $this->command($report->fresh()))->assertSessionHasNoErrors();
        $next = $report->nextRevision()->sole();
        $nextShow = route('work-orders.reports.show', [$wo, $next]);
        $this->post(route('work-orders.reports.submit', [$wo, $next]), $this->command($next))->assertRedirect($nextShow)->assertSessionHasNoErrors();
        $this->actingAs($reviewer)->post(route('work-orders.reports.review', [$wo, $next]), $this->command($next->fresh(), ['decision' => 'approved']))
            ->assertRedirect($nextShow)->assertSessionHasNoErrors();
        $this->assertSame('awaiting_acceptance', $wo->fresh()->status);
        $this->assertSame('approved', $next->fresh()->review_status);
        $this->assertSame('needs_revision', $report->fresh()->review_status);
    }

    public function test_http_create_and_partial_update_ignore_control_fields_and_surface_conflicts(): void
    {
        [$actor, $wo, $scope] = $this->context();
        $input = $this->content($wo);
        $this->actingAs($actor)->post(route('work-orders.reports.store', [$wo, $scope]), [...$input, 'created_by' => 99999, 'revision_no' => 99])->assertSessionHasNoErrors();
        $report = $wo->reports()->sole();
        $this->assertSame(1, $report->revision_no);
        $this->assertSame($actor->id, (int) $report->created_by);
        $update = ['command_key' => (string) Str::uuid(), 'expected_report_version' => 0, 'diagnosis' => 'Updated', 'review_status' => 'approved'];
        $url = route('work-orders.reports.update', [$wo, $report]);
        $this->patch($url, $update)->assertSessionHasNoErrors();
        $this->assertSame('Updated', $report->fresh()->diagnosis);
        $this->assertSame('Pasang kembali', $report->fresh()->action_taken);
        $this->assertSame('draft', $report->fresh()->review_status);
        $this->patchJson($url, [...$update, 'command_key' => (string) Str::uuid()])->assertConflict()->assertJsonValidationErrors(['expected_report_version']);
        $this->patchJson($url, [...$update, 'diagnosis' => 'Different'])->assertConflict()->assertJsonValidationErrors(['command_key']);
    }

    public function test_guest_nonmember_and_unprivileged_roles_cannot_access_or_mutate_reports(): void
    {
        [$actor, $wo, , $report] = $this->draft();
        $index = route('work-orders.reports.index', $wo);
        $show = route('work-orders.reports.show', [$wo, $report]);
        $this->get($index)->assertRedirect(route('login'));
        $this->get($show)->assertRedirect(route('login'));
        $stranger = User::factory()->create(['role' => 'teknisi']);
        $this->actingAs($stranger)->get($index)->assertNotFound();
        $this->get($show)->assertNotFound();
        $this->post(route('work-orders.reports.submit', [$wo, $report]), $this->command($report))->assertNotFound();
        $this->actingAs($actor)->post(route('work-orders.reports.review', [$wo, $report]), $this->command($report, ['decision' => 'approved']))->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => 'admin']))->post(route('work-orders.reports.submit', [$wo, $report]), $this->command($report))->assertForbidden();
        $this->assertSame('draft', $report->fresh()->review_status);
    }

    public function test_legacy_report_is_read_only_and_cross_parent_report_returns_404(): void
    {
        [$actor, $wo] = $this->context();
        $legacy = ServiceReport::query()->create(['work_order_id' => $wo->id, 'created_by' => $actor->id, 'diagnosis' => 'Legacy']);
        $this->actingAs($actor)->get(route('work-orders.reports.show', [$wo, $legacy]))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('report.legacy', true)->where('actions.update', null)->where('actions.submit', null)->where('actions.material', null));
        [, $other] = $this->context();
        $other->technicians()->attach($actor, ['assigned_at' => now()]);
        $this->get(route('work-orders.reports.show', [$other, $legacy]))->assertNotFound();
        $this->assertNull($legacy->fresh()->scope_item_id);
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
