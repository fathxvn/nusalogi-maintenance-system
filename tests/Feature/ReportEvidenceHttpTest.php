<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\CreateWorkOrderService;
use App\Services\ReportEvidenceService;
use App\Services\ServiceReportDraftService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReportEvidenceHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_and_download_are_scoped_and_do_not_expose_storage_keys(): void
    {
        Storage::fake('evidence');
        [$actor, $wo, , $report] = $this->draft();
        $file = UploadedFile::fake()->image('after.jpg');
        $input = [...$this->uploadPayload($file), 'upload_key' => (string) Str::uuid(), 'file' => $file, 'phase' => 'after'];
        $url = route('work-orders.reports.evidence.store', [$wo, $report]);

        $this->actingAs($actor)->from('/work-orders')->post($url, $input)->assertRedirect('/work-orders')->assertSessionHasNoErrors();

        $photo = $report->photos()->sole();
        $download = route('work-orders.reports.evidence.show', [$wo, $report, $photo]);
        $this->get($download)->assertOk()->assertDownload('after.jpg')->assertHeader('X-Content-Type-Options', 'nosniff');
        $stranger = User::factory()->create(['role' => 'teknisi']);
        $this->actingAs($stranger)->get($download)->assertNotFound();
        $this->actingAs($actor);
        $wo->technicians()->detach($actor);
        $this->get($download)->assertNotFound();
        $this->assertSame(1, $report->photos()->count());
    }

    public function test_guest_and_cross_parent_requests_cannot_download_evidence(): void
    {
        Storage::fake('evidence');
        [$actor, $wo, , $report] = $this->draft();
        $file = UploadedFile::fake()->image('after.jpg');
        $service = app(ReportEvidenceService::class);
        $stored = $service->upload($actor, $wo->id, $report->id, $file, $this->uploadPayload($file));
        $photo = $service->attach($actor, $wo->id, $report->id, ['command_key' => (string) Str::uuid(),
            'expected_report_version' => 0, 'evidence_file_id' => $stored->id, 'phase' => 'after']);
        [, $other] = $this->context();
        $other->technicians()->attach($actor, ['assigned_at' => now()]);

        $this->get(route('work-orders.reports.evidence.show', [$wo, $report, $photo]))->assertRedirect(route('login'));
        $this->actingAs($actor)->get(route('work-orders.reports.evidence.show', [$other, $report, $photo]))->assertNotFound();
        $actor->forceFill(['is_active' => false])->save();
        $this->get(route('work-orders.reports.evidence.show', [$wo, $report, $photo]))->assertRedirect(route('login'));
    }

    private function uploadPayload(UploadedFile $file): array
    {
        return ['command_key' => (string) Str::uuid(), 'sha256' => hash_file('sha256', $file->getRealPath()), 'expected_report_version' => 0];
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
