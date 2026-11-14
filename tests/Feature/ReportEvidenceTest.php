<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\PrivateEvidenceFile;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\CreateWorkOrderService;
use App\Services\ReportEvidenceService;
use App\Services\ServiceReportDraftService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ReportEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_private_upload_promotes_verified_bytes_and_attachment_retries_once(): void
    {
        Storage::fake('evidence');
        [$actor, $wo, , $report] = $this->draft();
        $upload = UploadedFile::fake()->image('after.jpg');
        $input = $this->uploadPayload($upload);
        $service = app(ReportEvidenceService::class);

        $file = $service->upload($actor, $wo->id, $report->id, $upload, $input);
        $retry = $service->upload($actor, $wo->id, $report->id, $upload, $input);

        $this->assertSame($file->id, $retry->id);
        $this->assertSame('ready', $file->state);
        $this->assertSame('evidence', $file->storage_disk);
        $this->assertSame($input['sha256'], $file->sha256);
        Storage::disk('evidence')->assertExists($file->storage_key);
        $this->assertSame(1, PrivateEvidenceFile::query()->count());
        $this->assertSame(1, OperationHistory::query()->where('action', 'evidence.promote')->count());
        $attach = ['command_key' => (string) Str::uuid(), 'expected_report_version' => 0,
            'evidence_file_id' => $file->id, 'phase' => 'after', 'caption' => 'Hasil perbaikan'];
        $photo = $service->attach($actor, $wo->id, $report->id, $attach);
        $replayed = $service->attach($actor, $wo->id, $report->id, $attach);
        $this->assertSame($photo->id, $replayed->id);
        $this->assertSame($file->storage_key, $photo->storage_path);
        $this->assertSame($file->id, (int) $photo->evidence_file_id);
        $this->assertSame(1, $report->fresh()->lock_version);
        $this->assertSame(1, $report->photos()->count());
        $this->assertFalse(config('filesystems.disks.evidence.serve'));
    }

    public function test_bad_hash_and_active_content_do_not_register_evidence(): void
    {
        Storage::fake('evidence');
        [$actor, $wo, , $report] = $this->draft();
        $image = UploadedFile::fake()->image('after.png');
        $this->assertConflict(fn () => app(ReportEvidenceService::class)->upload($actor, $wo->id, $report->id,
            $image, [...$this->uploadPayload($image), 'sha256' => str_repeat('0', 64)]), 'EVIDENCE_HASH_MISMATCH');
        $svg = UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        try {
            app(ReportEvidenceService::class)->upload($actor, $wo->id, $report->id, $svg, $this->uploadPayload($svg));
            $this->fail('SVG must not be accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
        $this->assertDatabaseCount('private_evidence_files', 0);
        Storage::disk('evidence')->assertEmpty();
    }

    public function test_tampered_ready_bytes_are_never_overwritten_by_retry(): void
    {
        Storage::fake('evidence');
        [$actor, $wo, , $report] = $this->draft();
        $upload = UploadedFile::fake()->image('after.jpg');
        $input = $this->uploadPayload($upload);
        $service = app(ReportEvidenceService::class);
        $file = $service->upload($actor, $wo->id, $report->id, $upload, $input);
        Storage::disk('evidence')->put($file->storage_key, 'tampered');

        $this->assertConflict(fn () => $service->upload($actor, $wo->id, $report->id, $upload, $input), 'EVIDENCE_INTEGRITY_FAILED');

        $this->assertSame('tampered', Storage::disk('evidence')->get($file->storage_key));
        $this->assertDatabaseCount('private_evidence_files', 1);
    }

    public function test_attachment_failure_keeps_ready_file_and_rolls_back_report(): void
    {
        Storage::fake('evidence');
        [$actor, $wo, , $report] = $this->draft();
        $upload = UploadedFile::fake()->image('after.jpg');
        $service = app(ReportEvidenceService::class);
        $file = $service->upload($actor, $wo->id, $report->id, $upload, $this->uploadPayload($upload));
        $input = ['command_key' => (string) Str::uuid(), 'expected_report_version' => 0, 'evidence_file_id' => $file->id, 'phase' => 'after'];
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failed): void {
            if (! $failed && str_starts_with($sql, 'insert into `operation_history`')) {
                $failed = true;
                throw new RuntimeException('Attachment audit failure');
            }
        });
        try {
            $service->attach($actor, $wo->id, $report->id, $input);
            $this->fail('Audit failure must roll back attachment.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Attachment audit failure', $exception->getMessage());
        }
        $this->assertSame(0, $report->fresh()->lock_version);
        $this->assertSame(0, $report->photos()->count());
        $this->assertSame('ready', $file->fresh()->state);
        Storage::disk('evidence')->assertExists($file->storage_key);
        $service->attach($actor, $wo->id, $report->id, $input);
        $this->assertSame(1, $report->fresh()->lock_version);
    }

    public function test_submitted_evidence_is_immutable_even_via_bulk_writer(): void
    {
        Storage::fake('evidence');
        [$actor, $wo, , $report] = $this->draft();
        $upload = UploadedFile::fake()->image('after.jpg');
        $service = app(ReportEvidenceService::class);
        $file = $service->upload($actor, $wo->id, $report->id, $upload, $this->uploadPayload($upload));
        $photo = $service->attach($actor, $wo->id, $report->id, ['command_key' => (string) Str::uuid(),
            'expected_report_version' => 0, 'evidence_file_id' => $file->id, 'phase' => 'after']);
        $report->forceFill(['submitted_at' => now(), 'review_status' => 'submitted'])->save();

        $this->assertImmutable(fn () => DB::table('service_reports')->where('id', $report->id)->update(['result' => 'Replaced']), 'SUBMITTED_REPORT_IMMUTABLE');
        $this->assertImmutable(fn () => DB::table('service_reports')->where('id', $report->id)->delete(), 'M1_REPORT_HISTORY_IMMUTABLE');
        $this->assertImmutable(fn () => DB::table('service_photos')->where('id', $photo->id)->update(['caption' => 'Replaced']), 'SUBMITTED_EVIDENCE_IMMUTABLE');
        $this->assertImmutable(fn () => DB::table('service_photos')->where('id', $photo->id)->delete(), 'SUBMITTED_EVIDENCE_IMMUTABLE');
        $this->assertImmutable(fn () => $report->photos()->create(['uploaded_by' => $actor->id, 'phase' => 'after', 'storage_path' => 'new.jpg']), 'SUBMITTED_EVIDENCE_IMMUTABLE');
        $this->assertImmutable(fn () => DB::table('private_evidence_files')->where('id', $file->id)->update(['sha256' => str_repeat('0', 64)]), 'EVIDENCE_METADATA_IMMUTABLE');
        $this->assertImmutable(fn () => $file->delete(), 'EVIDENCE_METADATA_IMMUTABLE');
        $this->assertConflict(fn () => $service->attach($actor, $wo->id, $report->id, ['command_key' => (string) Str::uuid(),
            'expected_report_version' => 1, 'evidence_file_id' => $file->id, 'phase' => 'after']), 'REPORT_STATE_CONFLICT');
    }

    public function test_upload_replay_and_attachment_recheck_membership_and_author(): void
    {
        Storage::fake('evidence');
        [$actor, $wo, , $report] = $this->draft();
        $upload = UploadedFile::fake()->image('after.jpg');
        $input = $this->uploadPayload($upload);
        $service = app(ReportEvidenceService::class);
        $service->upload($actor, $wo->id, $report->id, $upload, $input);
        $wo->technicians()->detach($actor);
        $this->expectException(AuthorizationException::class);
        $service->upload($actor, $wo->id, $report->id, $upload, $input);
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
