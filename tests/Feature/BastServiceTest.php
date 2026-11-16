<?php

namespace Tests\Feature;

use App\Models\BastItem;
use App\Models\BastVersion;
use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\PrivateEvidenceFile;
use App\Models\ServiceReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\BastContextService;
use App\Services\BastService;
use App\Services\CreateWorkOrderService;
use App\Services\ReportCorrectionService;
use App\Services\ServiceReportDraftService;
use App\Services\ServiceReportWorkflowService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class BastServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_compatible_multi_work_order_package_records_external_acceptance_and_freezes_versions(): void
    {
        Storage::fake('evidence');
        $this->freezeTime();
        [$actor, $tech, $wo, $first] = $this->approved();
        [, , $other, $second] = $this->approved($wo);
        $service = app(BastService::class);
        $version = $service->createDraft($actor, $this->package([$first, $second]));
        $this->assertSame(1, $version->version_no);
        $this->assertSame('draft', $version->status);
        $this->assertSame(2, $version->items()->count());
        $version = $this->issue($actor, $version);
        $this->assertSame('pending', $version->status);
        $this->assertCount(2, $version->scope_snapshot['items']);
        $decision = $this->decision($actor, $version);
        $accepted = $service->decide($actor, $version->id, $decision);
        $retry = $service->decide($actor, $version->id, $decision);
        $this->assertSame($accepted->id, $retry->id);
        $this->assertSame('accepted', $accepted->status);
        $this->assertSame('PIC Customer', $accepted->signer_name);
        $this->assertSame($actor->id, (int) $accepted->recorded_by);
        $this->assertNotNull($accepted->decided_at);
        $this->assertNotNull($accepted->recorded_at);
        $this->assertSame('awaiting_acceptance', $wo->fresh()->status);
        $this->assertSame('awaiting_acceptance', $other->fresh()->status);
        $this->assertSame(1, OperationHistory::query()->where('action', 'bast.decision')->count());
        $this->assertImmutable(fn () => $accepted->forceFill(['signer_name' => 'Replaced'])->save(), 'BAST_DECISION_IMMUTABLE');
        $this->assertImmutable(fn () => $accepted->items()->first()->forceFill(['ordinal' => 99])->save(), 'ISSUED_BAST_ITEMS_IMMUTABLE');
        $this->assertImmutable(fn () => $accepted->delete(), 'BAST_HISTORY_IMMUTABLE');
    }

    public function test_cross_customer_and_context_packages_are_rejected(): void
    {
        [$actor, , , $first] = $this->approved();
        [, , , $other] = $this->approved();
        $this->assertConflict(fn () => app(BastService::class)->createDraft($actor, $this->package([$first, $other])), 'BAST_CONTEXT_MISMATCH');
        $this->assertDatabaseCount('bast_documents', 0);
    }

    public function test_two_drafts_cannot_both_issue_the_same_scope_and_issued_content_cannot_change(): void
    {
        Storage::fake('evidence');
        [$actor, , , $report] = $this->approved();
        $service = app(BastService::class);
        $first = $service->createDraft($actor, $this->package([$report]));
        $second = $service->createDraft($actor, $this->package([$report]));
        $file = $this->upload($actor, $second, 'issued');
        $first = $this->issue($actor, $first);
        $this->assertConflict(fn () => $service->issue($actor, $second->id, [...$this->signer($second), 'command_key' => (string) Str::uuid(),
            'expected_version' => 0, 'evidence_file_id' => $file->id, 'scope_confirmed' => true]), 'BAST_SCOPE_ALREADY_CLAIMED');
        $this->assertSame('draft', $second->fresh()->status);
        $this->assertImmutable(fn () => $first->forceFill(['scope_snapshot' => []])->save(), 'ISSUED_BAST_IMMUTABLE');
        $this->assertImmutable(fn () => $first->items()->delete(), 'ISSUED_BAST_ITEMS_IMMUTABLE');
    }

    public function test_decision_on_superseded_version_is_rejected_and_version_chain_preserves_evidence(): void
    {
        Storage::fake('evidence');
        [$actor, , , $report] = $this->approved();
        $service = app(BastService::class);
        $old = $this->issue($actor, $service->createDraft($actor, $this->package([$report])));
        $decision = $this->decision($actor, $old);
        $next = $service->nextVersion($actor, $old->id, [...$this->package([$report]), 'expected_version' => $old->lock_version, 'reason' => 'Perbaiki dokumen BAST']);
        $this->assertSame($old->id, (int) $next->previous_version_id);
        $this->assertSame(2, $next->version_no);
        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertNotNull($old->fresh()->issued_file_id);
        $this->assertConflict(fn () => $service->decide($actor, $old->id, $decision), 'BAST_VERSION_CONFLICT');
        $this->assertSame('draft', $next->fresh()->status);
    }

    public function test_after_qa_document_correction_supersedes_multi_work_order_pending_package_atomically(): void
    {
        Storage::fake('evidence');
        [$actor, $tech, $wo, $report] = $this->approved();
        [, , $other, $otherReport] = $this->approved($wo);
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report, $otherReport])));
        $old = $report->fresh()->getAttributes();
        $next = app(ReportCorrectionService::class)->open($actor, $wo->id, $report->id, [...$this->command($report->fresh()),
            'author_id' => $tech->id, 'category' => 'document_fix', 'reason' => 'Perjelas narasi hasil uji']);
        $this->assertSame($report->id, $next->previous_revision_id);
        $this->assertSame(2, $next->revision_no);
        $this->assertSame('pending_review', $wo->fresh()->status);
        $this->assertSame('awaiting_acceptance', $other->fresh()->status);
        $this->assertSame('superseded', $version->fresh()->status);
        $this->assertSame($old, $report->fresh()->getAttributes());
        $history = OperationHistory::query()->where('action', 'report.correction.open')->sole();
        $this->assertSame([$version->id], $history->facts['superseded_version_ids']);
        $this->assertConflict(fn () => app(BastService::class)->createDraft($actor, $this->package([$report->fresh()])), 'BAST_APPROVED_HEAD_REQUIRED');
    }

    public function test_accepted_scope_cannot_be_corrected_retired_or_given_a_successor(): void
    {
        Storage::fake('evidence');
        $this->freezeTime();
        [$actor, $tech, $wo, $report] = $this->approved();
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report])));
        app(BastService::class)->decide($actor, $version->id, $this->decision($actor, $version));
        $this->assertConflict(fn () => app(ReportCorrectionService::class)->open($actor, $wo->id, $report->id, [...$this->command($report->fresh()),
            'author_id' => $tech->id, 'category' => 'document_fix', 'reason' => 'Attempt to change accepted scope']), 'ACCEPTED_SCOPE_IMMUTABLE');
        $this->assertImmutable(fn () => $report->scopeItem->forceFill(['disposition' => 'retired'])->save(), 'ACCEPTED_OR_TERMINAL_SCOPE_IMMUTABLE');
        $this->assertImmutable(function () use ($report): void {
            $next = $report->replicate();
            $next->forceFill(['revision_no' => 2, 'previous_revision_id' => $report->id])->save();
        }, 'ACCEPTED_SCOPE_IMMUTABLE');
        $this->assertSame(0, $report->nextRevision()->count());
    }

    public function test_technical_rejection_creates_successor_for_defect_scope_and_keeps_other_work_order_ready(): void
    {
        Storage::fake('evidence');
        $this->freezeTime();
        [$actor, $tech, $wo, $report] = $this->approved();
        [, , $other, $otherReport] = $this->approved($wo);
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report, $otherReport])));
        $decision = [...$this->decision($actor, $version), 'decision' => 'rejected', 'reason' => 'Feed masih putus',
            'category' => 'technical_rework', 'rework_scope_authors' => [$report->scope_item_id => $tech->id]];
        $rejected = app(BastService::class)->decide($actor, $version->id, $decision);
        $this->assertSame('rejected', $rejected->status);
        $this->assertSame('rework', $wo->fresh()->status);
        $this->assertSame('awaiting_acceptance', $other->fresh()->status);
        $this->assertSame('draft', $report->nextRevision()->sole()->review_status);
        $this->assertSame(0, $otherReport->nextRevision()->count());
        $this->assertSame('approved', $report->fresh()->review_status);
        $this->assertDatabaseCount('service_reports', 3);
    }

    public function test_package_read_requires_access_to_every_work_order_and_rechecks_revocation(): void
    {
        [$actor, $tech, $wo, $report] = $this->approved();
        [, , $other, $otherReport] = $this->approved($wo);
        $version = app(BastService::class)->createDraft($actor, $this->package([$report, $otherReport]));
        try {
            app(BastContextService::class)->visibleVersion($tech, $version->id);
            $this->fail('Partial membership must not reveal a package.');
        } catch (HttpException $exception) {
            $this->assertSame(404, $exception->getStatusCode());
        }
        $other->technicians()->attach($tech, ['assigned_at' => now()]);
        $this->assertSame($version->id, app(BastContextService::class)->visibleVersion($tech, $version->id)->id);
        $wo->technicians()->detach($tech);
        $this->expectException(HttpException::class);
        app(BastContextService::class)->visibleVersion($tech, $version->id);
    }

    #[DataProvider('atomicCommands')]
    public function test_history_failure_rolls_back_entire_acceptance_command_and_same_key_retries(string $operation): void
    {
        Storage::fake('evidence');
        $this->freezeTime();
        [$actor, $tech, $wo, $report] = $this->approved();
        $service = app(BastService::class);
        $version = $service->createDraft($actor, $this->package([$report]));
        if (! in_array($operation, ['issue', 'update'], true)) {
            $version = $this->issue($actor, $version);
        }
        $data = ['command_key' => (string) Str::uuid(), 'expected_version' => $version->lock_version, 'reason' => 'Audited correction'];
        if ($operation === 'issue') {
            $file = $this->upload($actor, $version, 'issued');
            $data = [...$data, ...$this->signer($version), 'evidence_file_id' => $file->id, 'scope_confirmed' => true];
        } elseif (in_array($operation, ['decide', 'technical_rejection'], true)) {
            $data = $this->decision($actor, $version);
            if ($operation === 'technical_rejection') {
                $data = [...$data, 'decision' => 'rejected', 'category' => 'technical_rework', 'reason' => 'Defect',
                    'rework_scope_authors' => [$report->scope_item_id => $tech->id]];
            }
        } elseif ($operation === 'correction') {
            $data = [...$this->command($report->fresh()), 'author_id' => $tech->id, 'category' => 'document_fix', 'reason' => 'Correct result'];
        } elseif (in_array($operation, ['update', 'nextVersion'], true)) {
            $data = [...$this->package([$report]), ...$data];
        }
        $snapshot = fn (): array => [BastVersion::query()->get()->toArray(), BastItem::query()->get()->toArray(), ServiceReport::query()->get()->toArray(),
            $wo->fresh()->getAttributes(), $wo->updates()->count(), OperationHistory::query()->count()];
        $before = $snapshot();
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failed): void {
            if (! $failed && str_starts_with($sql, 'insert into `operation_history`')) {
                $failed = true;
                throw new RuntimeException('Acceptance history failure');
            }
        });
        $run = fn () => match ($operation) {
            'correction' => app(ReportCorrectionService::class)->open($actor, $wo->id, $report->id, $data),
            'technical_rejection' => $service->decide($actor, $version->id, $data),
            'update' => $service->updateDraft($actor, $version->id, $data),
            default => $service->{$operation}($actor, $version->id, $data),
        };
        try {
            $run();
            $this->fail('History failure must roll back every domain effect.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Acceptance history failure', $exception->getMessage());
        }
        $this->assertTrue($failed);
        $this->assertSame($before, $snapshot());
        $run();
        $this->assertSame($before[5] + 1, OperationHistory::query()->count());
    }

    public static function atomicCommands(): array
    {
        return array_combine(['issue', 'decide', 'technical_rejection', 'correction', 'cancel', 'update', 'nextVersion'],
            array_map(fn (string $operation): array => [$operation], ['issue', 'decide', 'technical_rejection', 'correction', 'cancel', 'update', 'nextVersion']));
    }

    public function test_external_decision_time_is_distinct_from_recording_and_future_decisions_are_rejected(): void
    {
        Storage::fake('evidence');
        $this->freezeTime();
        [$actor, , , $report] = $this->approved();
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report])));
        $input = $this->decision($actor, $version);
        try {
            app(BastService::class)->decide($actor, $version->id, [...$input, 'decided_at' => now()->addHour()->toISOString()]);
            $this->fail('Future decision must fail.');
        } catch (ValidationException $exception) {
            $this->assertSame(['Waktu keputusan tidak boleh di masa depan.'], $exception->errors()['decided_at']);
        }
        $past = now()->subDay()->toISOString();
        $accepted = app(BastService::class)->decide($actor, $version->id, [...$input, 'decided_at' => $past]);
        $this->assertSame($past, $accepted->decided_at->toISOString());
        $this->assertSame(now()->toISOString(), $accepted->recorded_at->toISOString());
    }

    public function test_coordinator_can_assign_successor_to_another_member_after_rejected_author_loses_access(): void
    {
        [$author, $wo, $scope] = $this->context();
        $report = app(ServiceReportDraftService::class)->createDraft($author, $wo->id, $scope->id, $this->content($wo));
        $workflow = app(ServiceReportWorkflowService::class);
        $report = $workflow->submit($author, $wo->id, $report->id, $this->command($report));
        $coordinator = User::factory()->create(['role' => 'koordinator']);
        $report = $workflow->review($coordinator, $wo->id, $report->id, [...$this->command($report),
            'decision' => 'needs_revision', 'category' => 'technical_rework', 'reason' => 'Perbaiki hasil teknis']);
        $replacement = User::factory()->create(['role' => 'teknisi']);
        $wo->technicians()->attach($replacement, ['assigned_at' => now()]);
        $author->forceFill(['is_active' => false])->save();
        $input = [...$this->command($report), 'author_id' => $replacement->id, 'category' => 'document_fix', 'reason' => 'Penulis lama tidak aktif'];
        $this->assertConflict(fn () => app(ReportCorrectionService::class)->open($coordinator, $wo->id, $report->id, $input), 'REWORK_CATEGORY_MISMATCH');
        $next = app(ReportCorrectionService::class)->open($coordinator, $wo->id, $report->id, [...$input, 'category' => 'technical_rework']);
        $this->assertSame($replacement->id, (int) $next->created_by);
        $this->assertSame($author->id, (int) $report->fresh()->created_by);
        $this->assertSame('rework', $wo->fresh()->status);
        $this->assertSame('needs_revision', $report->fresh()->review_status);
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
