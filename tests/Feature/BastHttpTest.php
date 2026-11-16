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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BastHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_issued_and_signed_uploads_record_acceptance_through_scoped_http_pages(): void
    {
        Storage::fake('evidence');
        $this->freezeTime();
        [$actor, $tech, $wo, $report] = $this->approved();
        $this->actingAs($actor)->get(route('work-orders.acceptance.index', $wo))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('work-orders/acceptance/index')->has('candidates', 1)->where('candidates.0.report_id', $report->id));
        $this->post(route('work-orders.acceptance.store', $wo), $this->package([$report]))->assertSessionHasNoErrors();
        $version = BastVersion::query()->sole();
        $this->get(route('bast.show', $version))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('work-orders/acceptance/show')->has('items', 1)->where('version.status', 'draft'));
        $issuedFile = UploadedFile::fake()->createWithContent('issued.pdf', "%PDF-1.4\nIssued fixture\n%%EOF");
        $issued = [...$this->signer($version), 'command_key' => (string) Str::uuid(), 'upload_key' => (string) Str::uuid(),
            'expected_version' => 0, 'scope_confirmed' => true, 'file' => $issuedFile, 'sha256' => hash_file('sha256', $issuedFile->getRealPath())];
        $this->post(route('bast.issue', $version), $issued)->assertRedirect(route('bast.show', $version))->assertSessionHasNoErrors();
        $this->assertSame('pending', $version->fresh()->status);
        $signed = UploadedFile::fake()->createWithContent('signed.pdf', "%PDF-1.4\nSigned fixture\n%%EOF");
        $decision = [...$this->signer($version), 'command_key' => (string) Str::uuid(), 'upload_key' => (string) Str::uuid(),
            'expected_version' => 1, 'scope_confirmed' => true, 'decision' => 'accepted', 'decided_at' => now()->toISOString(),
            'file' => $signed, 'sha256' => hash_file('sha256', $signed->getRealPath())];
        $this->post(route('bast.decide', $version), $decision)->assertSessionHasNoErrors();
        $this->assertSame('accepted', $version->fresh()->status);
        $this->get(route('bast.show', $version))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('actions.issue', null)->where('actions.decide', null)->where('actions.cancel', null)->where('actions.revise', null));
        $this->actingAs($tech)->get(route('bast.download', [$version, 'decision']))->assertOk()->assertDownload('signed.pdf');
        $this->get(route('work-orders.reports.show', [$wo, $report]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('actions.correction', null));
    }

    public function test_partial_package_membership_is_denied_on_list_detail_and_download_including_revocation(): void
    {
        Storage::fake('evidence');
        [$actor, $tech, $wo, $report] = $this->approved();
        [, , $other, $otherReport] = $this->approved($wo);
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report, $otherReport])));
        $this->actingAs($tech)->get(route('work-orders.acceptance.index', $wo))->assertOk()->assertInertia(fn (Assert $page) => $page->where('versions.total', 0));
        $this->get(route('bast.show', $version))->assertNotFound();
        $this->get(route('bast.download', [$version, 'issued']))->assertNotFound();
        $other->technicians()->attach($tech, ['assigned_at' => now()]);
        $this->get(route('bast.download', [$version, 'issued']))->assertOk()->assertDownload('issued.pdf');
        $other->technicians()->detach($tech);
        $this->get(route('bast.download', [$version, 'issued']))->assertNotFound();
        $this->post(route('bast.cancel', $version), ['command_key' => (string) Str::uuid(), 'expected_version' => 1, 'reason' => 'Forbidden'])->assertForbidden();
    }

    public function test_missing_or_modified_file_blocks_download_without_changing_accepted_decision(): void
    {
        Storage::fake('evidence');
        $this->freezeTime();
        [$actor, , , $report] = $this->approved();
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report])));
        $version = app(BastService::class)->decide($actor, $version->id, $this->decision($actor, $version));
        Storage::disk('evidence')->put($version->decisionFile->storage_key, 'Tampered');
        $this->actingAs($actor)->getJson(route('bast.download', [$version, 'decision']))->assertConflict()->assertJsonValidationErrors('file');
        $this->assertSame('accepted', $version->fresh()->status);
        $this->assertNotNull($version->fresh()->decided_at);
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
