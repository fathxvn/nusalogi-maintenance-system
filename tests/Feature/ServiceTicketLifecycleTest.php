<?php

namespace Tests\Feature;

use App\Models\BastItem;
use App\Models\BastVersion;
use App\Models\Customer;
use App\Models\OperationHistory;
use App\Models\PrivateEvidenceFile;
use App\Models\ServiceReport;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderScopeItem;
use App\Services\BastService;
use App\Services\CreateWorkOrderService;
use App\Services\ServiceReportDraftService;
use App\Services\ServiceReportWorkflowService;
use App\Services\ServiceTicketLifecycleService;
use App\Services\ServiceTicketService;
use App\Services\WorkOrderLifecycleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ServiceTicketLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_multi_work_order_ticket_resolves_only_after_every_child_has_audited_final_disposition(): void
    {
        Storage::fake('evidence');
        [$actor, , $first, $report] = $this->approved();
        $ticket = ServiceTicket::factory()->create(['status' => 'approved', 'site_id' => $first->customer_site_id,
            'customer_id' => $first->site->customer_id, 'coverage_disposition' => 'covered']);
        $first->forceFill(['origin_kind' => 'ticket', 'ticket_id' => $ticket->id])->save();
        $second = app(CreateWorkOrderService::class)->create($actor, [
            'command_key' => (string) Str::uuid(), 'origin_kind' => 'ticket', 'ticket_id' => $ticket->id,
            'expected_ticket_version' => 0, 'initial_scope' => ['description' => 'Scope kedua', 'acceptance_criteria' => 'Normal'],
            'customer_site_id' => $first->customer_site_id, 'title' => 'WO kedua', 'description' => 'Tindak lanjut',
            'service_type' => 'maintenance', 'category' => 'cctv', 'priority' => 'medium', 'asset_ids' => [],
        ]);
        $version = $this->issue($actor, app(BastService::class)->createDraft($actor, $this->package([$report])));
        app(BastService::class)->decide($actor, $version->id, $this->decision($actor, $version));
        app(WorkOrderLifecycleService::class)->close($actor, $first->id, $this->woCommand($first->fresh()));
        $this->assertSame('in_fulfillment', $ticket->fresh()->status);
        $service = app(ServiceTicketLifecycleService::class);
        $this->assertConflict(fn () => $service->change($actor, $ticket->id, 'resolve', $this->ticketCommand($ticket->fresh())), 'TICKET_WORK_ORDERS_STILL_OPEN');
        $this->assertNull($ticket->fresh()->resolved_at);
        app(WorkOrderLifecycleService::class)->change($actor, $second->id, 'cancel', $this->woCommand($second, ['reason' => 'Scope kedua tidak diperlukan sebelum eksekusi']));
        $input = $this->ticketCommand($ticket->fresh());
        $resolved = $service->change($actor, $ticket->id, 'resolve', $input);
        $replay = $service->change($actor, $ticket->id, 'resolve', $input);
        $this->assertSame('resolved', $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertSame($resolved->lock_version, $replay->lock_version);
        $history = OperationHistory::query()->where('action', 'ticket.resolve')->sole();
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $history->facts['work_order_ids']);
        $this->assertSame('all_work_orders_final', $history->facts['resolution_kind']);
    }

    public function test_legacy_final_state_without_closure_provenance_cannot_resolve_a_ticket(): void
    {
        [$actor, , $wo] = $this->approved();
        $ticket = ServiceTicket::factory()->create(['status' => 'in_fulfillment']);
        $wo->forceFill(['ticket_id' => $ticket->id, 'status' => 'closed'])->save();
        $this->assertConflict(fn () => app(ServiceTicketLifecycleService::class)->change($actor, $ticket->id, 'resolve', $this->ticketCommand($ticket)), 'TICKET_FULFILLMENT_PROVENANCE_REQUIRED');
        $this->assertSame('in_fulfillment', $ticket->fresh()->status);
    }

    public function test_remote_resolution_requires_triage_known_coverage_and_confirmed_note(): void
    {
        $actor = User::factory()->create(['role' => 'koordinator']);
        $ticket = ServiceTicket::factory()->create(['status' => 'triaged', 'coverage_disposition' => 'unknown']);
        $service = app(ServiceTicketLifecycleService::class);
        $this->assertConflict(fn () => $service->change($actor, $ticket->id, 'resolve', $this->ticketCommand($ticket)), 'REMOTE_RESOLUTION_NOT_ELIGIBLE');
        $ticket->forceFill(['coverage_disposition' => 'authorized', 'authorization_ref' => 'Customer authorization 002'])->save();
        $resolved = $service->change($actor, $ticket->id, 'resolve', $this->ticketCommand($ticket));
        $this->assertSame('resolved', $resolved->status);
        $this->assertSame('Hasil penanganan terkonfirmasi', $resolved->resolution_note);
        $this->assertSame('remote', OperationHistory::query()->where('action', 'ticket.resolve')->sole()->facts['resolution_kind']);
    }

    public function test_waiting_information_can_be_retriaged_and_waiting_authorization_needs_explicit_coverage(): void
    {
        $actor = User::factory()->create(['role' => 'koordinator']);
        $ticket = ServiceTicket::factory()->create();
        $site = Customer::query()->create(['name' => 'Customer'])->sites()->create(['site_name' => 'Site', 'address' => 'Bekasi']);
        $service = app(ServiceTicketLifecycleService::class);
        $waiting = $service->change($actor, $ticket->id, 'wait_information', $this->ticketCommand($ticket));
        $this->assertSame('waiting_information', $waiting->status);
        $triaged = app(ServiceTicketService::class)->decide($actor, $ticket->id, 'triage', [
            'command_key' => (string) Str::uuid(), 'expected_ticket_version' => $waiting->lock_version,
            'site_id' => $site->id, 'coverage_disposition' => 'unknown']);
        $waiting = $service->change($actor, $ticket->id, 'await_authorization', $this->ticketCommand($triaged));
        try {
            app(ServiceTicketService::class)->decide($actor, $ticket->id, 'approve', [
                'command_key' => (string) Str::uuid(), 'expected_ticket_version' => $waiting->lock_version]);
            $this->fail('Unknown coverage must not be approved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('coverage_disposition', $exception->errors());
        }
        $approved = app(ServiceTicketService::class)->decide($actor, $ticket->id, 'approve', [
            'command_key' => (string) Str::uuid(), 'expected_ticket_version' => $waiting->lock_version,
            'coverage_disposition' => 'authorized', 'authorization_ref' => 'Customer instruction 003']);
        $this->assertSame('approved', $approved->status);
        $this->assertSame('Customer instruction 003', $approved->authorization_ref);
    }

    public function test_duplicate_requires_a_different_nonduplicate_canonical_ticket_and_cannot_form_a_cycle(): void
    {
        $actor = User::factory()->create(['role' => 'koordinator']);
        $a = ServiceTicket::factory()->create();
        $b = ServiceTicket::factory()->create();
        $service = app(ServiceTicketLifecycleService::class);
        $this->assertConflict(fn () => $service->change($actor, $a->id, 'duplicate', $this->ticketCommand($a, ['duplicate_of_id' => $a->id])), 'CANONICAL_TICKET_REQUIRED');
        $duplicate = $service->change($actor, $a->id, 'duplicate', $this->ticketCommand($a, ['duplicate_of_id' => $b->id]));
        $this->assertSame($b->id, $duplicate->duplicate_of_id);
        $this->assertConflict(fn () => $service->change($actor, $b->id, 'duplicate', $this->ticketCommand($b, ['duplicate_of_id' => $a->id])), 'CANONICAL_TICKET_REQUIRED');
        $this->assertNull($b->fresh()->duplicate_of_id);
    }

    #[DataProvider('terminalTicketActions')]
    public function test_terminal_decisions_require_version_and_roll_back_when_history_fails(string $action): void
    {
        $actor = User::factory()->create(['role' => 'koordinator']);
        $ticket = ServiceTicket::factory()->create();
        $service = app(ServiceTicketLifecycleService::class);
        $this->assertConflict(fn () => $service->change($actor, $ticket->id, $action, $this->ticketCommand($ticket, ['expected_ticket_version' => 99])), 'TICKET_VERSION_CONFLICT');
        $before = $ticket->fresh()->getAttributes();
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql) use (&$failed): void {
            if (! $failed && str_starts_with($sql, 'insert into `operation_history`')) {
                $failed = true;
                throw new RuntimeException('Ticket history failed');
            }
        });
        $input = $this->ticketCommand($ticket);
        try {
            $service->change($actor, $ticket->id, $action, $input);
            $this->fail('History must be atomic.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Ticket history failed', $exception->getMessage());
        }
        $this->assertSame($before, $ticket->fresh()->getAttributes());
        $service->change($actor, $ticket->id, $action, $input);
        $this->assertConflict(fn () => $service->change($actor, $ticket->id, $action, [...$input, 'note' => 'Changed']), 'COMMAND_KEY_REUSED');
        $actor->forceFill(['is_active' => false])->save();
        $this->expectException(AuthorizationException::class);
        $service->change($actor, $ticket->id, $action, $input);
    }

    public static function terminalTicketActions(): array
    {
        return ['reject' => ['reject'], 'cancel' => ['cancel']];
    }

    public function test_http_role_boundary_and_ticket_actions_use_current_version(): void
    {
        $this->withoutVite();
        $ticket = ServiceTicket::factory()->create();
        $admin = User::factory()->create(['role' => 'admin']);
        $coordinator = User::factory()->create(['role' => 'koordinator']);
        $tech = User::factory()->create(['role' => 'teknisi']);
        $url = route('tickets.lifecycle', [$ticket, 'reject']);
        $this->actingAs($admin)->postJson($url, $this->ticketCommand($ticket))->assertForbidden();
        $this->actingAs($tech)->postJson($url, $this->ticketCommand($ticket))->assertForbidden();
        $this->actingAs($coordinator)->get(route('tickets.show', $ticket))->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('tickets/show')->where('lifecycleActions.reject.url', $url)->where('ticket.lock_version', 0)->etc());
        $this->actingAs($coordinator)->post($url, $this->ticketCommand($ticket))->assertRedirectToRoute('tickets.show', $ticket)->assertSessionHasNoErrors();
        $this->assertSame('rejected', $ticket->fresh()->status);
    }

    public function test_ticket_cannot_be_cancelled_while_a_work_order_is_open(): void
    {
        [$actor, , $wo] = $this->approved();
        $ticket = ServiceTicket::factory()->create(['status' => 'in_fulfillment']);
        $wo->forceFill(['ticket_id' => $ticket->id])->save();
        $this->assertConflict(fn () => app(ServiceTicketLifecycleService::class)->change($actor, $ticket->id, 'cancel', $this->ticketCommand($ticket)), 'TICKET_CANCELLATION_REQUIRES_CHILD_DISPOSITION');
        $this->assertSame('in_fulfillment', $ticket->fresh()->status);
    }

    private function ticketCommand(ServiceTicket $ticket, array $extra = []): array
    {
        return ['command_key' => (string) Str::uuid(), 'expected_ticket_version' => $ticket->lock_version, 'note' => 'Hasil penanganan terkonfirmasi', ...$extra];
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
