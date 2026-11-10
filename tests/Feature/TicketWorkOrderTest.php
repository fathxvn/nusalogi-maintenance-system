<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\OperationHistory;
use App\Models\ServiceTicket;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\CreateWorkOrderService;
use App\Services\ServiceTicketService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class TicketWorkOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['m1.writer_mode' => 'expand']);
        $this->withoutVite();
    }

    /** TW01 */
    public function test_admin_intake_records_a_ticket_without_work_order_effects(): void
    {
        $this->travelTo('2026-11-10 09:00:00');
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'command_key' => (string) Str::uuid(),
            'kind' => 'complaint',
            'channel' => 'phone',
            'contact_label' => 'Customer PIC',
            'description' => 'Camera offline',
        ];

        $response = $this->actingAs($admin)->post(route('tickets.store'), $payload);

        $ticket = ServiceTicket::query()->sole();
        $response->assertRedirectToRoute('tickets.show', $ticket)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('service_tickets', [
            'id' => $ticket->id, 'created_by' => $admin->id, 'status' => 'new',
            'customer_id' => null, 'site_id' => null, 'coverage_disposition' => 'unknown',
            'received_at' => '2026-11-10 09:00:00',
        ]);
        $this->assertSame('ticket.intake', OperationHistory::query()->sole()->action);
        $this->assertNoWorkOrderEffects();
    }

    public function test_intake_replay_returns_the_same_ticket_and_rejects_changed_payload_with_409(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $payload = [
            'command_key' => (string) Str::uuid(), 'kind' => 'request',
            'channel' => 'email', 'contact_label' => 'Customer PIC', 'description' => 'Survey',
        ];
        $service = app(ServiceTicketService::class);
        $first = $service->intake($admin, $payload);

        $replay = $service->intake($admin, $payload);

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('service_tickets', 1);
        $this->assertDatabaseCount('operation_history', 1);
        $this->actingAs($admin)->postJson(route('tickets.store'), [
            ...$payload, 'description' => 'Changed request',
        ])->assertConflict()->assertJsonPath('errors.command_key.0', 'COMMAND_KEY_REUSED');
        $this->assertSame('Survey', $first->fresh()->description);
    }

    public function test_ticket_pages_expose_role_specific_actions_and_active_inertia_navigation(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = ServiceTicket::factory()->create(['created_by' => $admin->id]);

        $response = $this->actingAs($admin)->get(route('tickets.show', $ticket));

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('tickets/show')->where('links.triage', null)
            ->where('links.approve', null)->where('links.createWorkOrder', null)->etc());
        $navigation = collect($response->inertiaProps('navigation'))->firstWhere('id', 'tickets');
        $this->assertTrue($navigation['active']);
        $this->assertTrue($navigation['inertia']);
    }

    public function test_coordinator_can_triage_then_approve_with_separate_audited_commands(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $ticket = ServiceTicket::factory()->create();
        $service = app(ServiceTicketService::class);
        $triage = [
            'command_key' => (string) Str::uuid(), 'expected_ticket_version' => 0,
            'site_id' => $site->id, 'coverage_disposition' => 'covered',
        ];
        $service->decide($coordinator, $ticket->id, 'triage', $triage);

        $this->actingAs($coordinator)->post(route('tickets.approve', $ticket), [
            'command_key' => (string) Str::uuid(), 'expected_ticket_version' => 1,
        ])->assertRedirectToRoute('tickets.show', $ticket)->assertSessionHasNoErrors();

        $this->assertDatabaseHas('service_tickets', [
            'id' => $ticket->id, 'status' => 'approved', 'lock_version' => 2,
            'site_id' => $site->id, 'customer_id' => $site->customer_id,
        ]);
        $this->assertSame(['ticket.triage', 'ticket.approve'], OperationHistory::query()->orderBy('id')->pluck('action')->all());
        $replayed = $service->decide($coordinator, $ticket->id, 'triage', $triage);
        $this->assertSame('approved', $replayed->status);
        $this->assertDatabaseCount('operation_history', 2);
        $this->assertNoWorkOrderEffects();
    }

    public function test_retriage_can_clear_an_old_asset_when_the_site_changes(): void
    {
        $coordinator = $this->coordinator();
        $oldSite = $this->site();
        $newSite = $this->site();
        $asset = $this->asset($oldSite);
        $ticket = ServiceTicket::factory()->create([
            'site_id' => $oldSite->id, 'customer_id' => $oldSite->customer_id,
            'primary_asset_id' => $asset->id, 'status' => 'triaged',
        ]);

        $this->actingAs($coordinator)->post(route('tickets.triage', $ticket), [
            'command_key' => (string) Str::uuid(), 'expected_ticket_version' => 0,
            'site_id' => $newSite->id, 'primary_asset_id' => null,
            'coverage_disposition' => 'unknown',
        ])->assertSessionHasNoErrors()->assertRedirectToRoute('tickets.show', $ticket);

        $this->assertDatabaseHas('service_tickets', [
            'id' => $ticket->id, 'site_id' => $newSite->id,
            'customer_id' => $newSite->customer_id, 'primary_asset_id' => null, 'lock_version' => 1,
        ]);
    }

    #[DataProvider('unapprovedCoverage')]
    public function test_approval_requires_known_coverage_and_an_authorization_reference(?string $coverage, ?string $reference): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $ticket = $this->approvedTicket($site, [
            'status' => 'triaged', 'coverage_disposition' => $coverage, 'authorization_ref' => $reference,
        ]);

        $this->actingAs($coordinator)->post(route('tickets.approve', $ticket), [
            'command_key' => (string) Str::uuid(), 'expected_ticket_version' => 0,
        ])->assertSessionHasErrors('coverage_disposition');

        $this->assertSame('triaged', $ticket->fresh()->status);
        $this->assertDatabaseCount('operation_history', 0);
        $this->assertNoWorkOrderEffects();
    }

    public static function unapprovedCoverage(): array
    {
        return [
            'unknown' => ['unknown', null],
            'missing coverage' => [null, null],
            'authorized without reference' => ['authorized', null],
        ];
    }

    /** TW02, TW14 */
    public function test_ticket_work_order_creates_one_scope_and_command_history_with_server_owned_fields(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $ticket = $this->approvedTicket($site);
        $asset = $this->asset($site);
        $payload = [
            ...$this->payload($site, $ticket), 'asset_ids' => [$asset->id],
            'wo_number' => 'FORGED', 'created_by' => 999999, 'status' => 'closed',
            'scope_no' => 9, 'is_required' => false,
        ];

        $this->actingAs($coordinator)->post(route('work-orders.m1.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index')
            ->assertSessionHas('success', 'Work Order berhasil ditambahkan.');

        $workOrder = WorkOrder::query()->sole();
        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id, 'created_by' => $coordinator->id, 'status' => 'pending',
            'origin_kind' => 'ticket', 'ticket_id' => $ticket->id, 'manual_reason' => null,
            'acceptance_context_ref' => 'ticket:'.$ticket->id,
        ]);
        $this->assertMatchesRegularExpression('/^WO-[0-9A-HJKMNP-TV-Z]{26}$/', $workOrder->wo_number);
        $this->assertDatabaseHas('work_order_scope_items', [
            'work_order_id' => $workOrder->id, 'scope_no' => 1, 'is_required' => true,
            'description' => 'Restore camera feed', 'acceptance_criteria' => 'Live feed works',
            'disposition' => 'active', 'created_by' => $coordinator->id,
        ]);
        $this->assertDatabaseHas('work_order_assets', ['work_order_id' => $workOrder->id, 'asset_id' => $asset->id]);
        $history = OperationHistory::query()->sole();
        $this->assertSame('work_order.create', $history->action);
        $this->assertSame($workOrder->id, $history->facts['work_order_id']);
        $this->assertSame($workOrder->scopeItems()->sole()->id, $history->facts['scope_id']);
        $this->assertSame('C2', $history->facts['cohort']);
        $this->assertDatabaseHas('service_tickets', ['id' => $ticket->id, 'status' => 'in_fulfillment', 'lock_version' => 1]);
        $this->assertDatabaseCount('work_order_scope_items', 1);
        $this->assertDatabaseCount('operation_history', 1);
        $this->assertDatabaseCount('work_order_technicians', 0);
        $this->assertDatabaseCount('work_order_updates', 0);
    }

    public function test_history_asset_ids_remain_a_list_when_the_ticket_primary_asset_is_not_selected(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $primaryAsset = $this->asset($site);
        $selectedAsset = $this->asset($site);
        $ticket = $this->approvedTicket($site, ['primary_asset_id' => $primaryAsset->id]);

        $this->actingAs($coordinator)->post(route('work-orders.m1.store'), [
            ...$this->payload($site, $ticket), 'asset_ids' => [$selectedAsset->id],
        ])->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');

        $this->assertSame([$selectedAsset->id], OperationHistory::query()->sole()->facts['asset_ids']);
        $this->assertDatabaseCount('work_order_assets', 1);
        $this->assertDatabaseHas('work_order_assets', ['asset_id' => $selectedAsset->id]);
    }

    /** TW03 */
    public function test_new_command_key_can_create_a_second_work_order_for_the_same_ticket(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $ticket = $this->approvedTicket($site);
        $service = app(CreateWorkOrderService::class);
        $first = $service->create($coordinator, $this->payload($site, $ticket));
        $ticket->refresh();

        $this->actingAs($coordinator)->post(route('work-orders.m1.store'), $this->payload($site, $ticket))
            ->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');

        $this->assertModelExists($first);
        $this->assertSame(2, $ticket->workOrders()->count());
        $this->assertDatabaseCount('work_order_scope_items', 2);
        $this->assertDatabaseCount('operation_history', 2);
        $this->assertSame(2, $ticket->fresh()->lock_version);
    }

    /** TW04 */
    public function test_replay_returns_committed_work_order_before_the_stale_ticket_version_guard(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $ticket = $this->approvedTicket($site);
        $payload = $this->payload($site, $ticket);
        $first = app(CreateWorkOrderService::class)->create($coordinator, $payload);

        $this->actingAs($coordinator)->post(route('work-orders.m1.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');

        $this->assertSame($first->id, WorkOrder::query()->sole()->id);
        $this->assertDatabaseCount('work_order_scope_items', 1);
        $this->assertDatabaseCount('operation_history', 1);
        $this->assertSame(1, $ticket->fresh()->lock_version);
    }

    public function test_asset_id_order_and_integer_representation_do_not_change_a_command_digest(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $firstAsset = $this->asset($site);
        $secondAsset = $this->asset($site);
        $payload = [...$this->payload($site), 'asset_ids' => [(string) $secondAsset->id, (string) $firstAsset->id]];
        $service = app(CreateWorkOrderService::class);
        $first = $service->create($coordinator, $payload);

        $replay = $service->create($coordinator, [...$payload, 'asset_ids' => [$firstAsset->id, $secondAsset->id]]);

        $this->assertSame($first->id, $replay->id);
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('work_order_assets', 2);
        $this->assertDatabaseCount('operation_history', 1);
    }

    /** TW06 */
    #[DataProvider('changedCommands')]
    public function test_changed_payload_with_the_same_key_returns_409_without_overwriting_the_result(string $field, mixed $value): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $payload = $this->payload($site);
        $workOrder = app(CreateWorkOrderService::class)->create($coordinator, $payload);
        $changed = $payload;
        data_set($changed, $field, $value);

        $this->actingAs($coordinator)->postJson(route('work-orders.m1.store'), $changed)
            ->assertConflict()->assertJsonPath('errors.command_key.0', 'COMMAND_KEY_REUSED');

        $this->assertSame($workOrder->id, WorkOrder::query()->sole()->id);
        $this->assertSame('Camera inspection', $workOrder->fresh()->title);
        $this->assertDatabaseCount('work_order_scope_items', 1);
        $this->assertDatabaseCount('operation_history', 1);
    }

    public static function changedCommands(): array
    {
        return [
            'title' => ['title', 'A different job'],
            'scope' => ['initial_scope.description', 'Different scope'],
            'criteria' => ['initial_scope.acceptance_criteria', 'Different criteria'],
            'manual reason' => ['manual_reason', 'Different authorization context'],
        ];
    }

    /** TW06 */
    public function test_reusing_a_key_with_a_new_expected_ticket_version_returns_409(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $ticket = $this->approvedTicket($site);
        $payload = $this->payload($site, $ticket);
        app(CreateWorkOrderService::class)->create($coordinator, $payload);

        $this->actingAs($coordinator)->postJson(route('work-orders.m1.store'), [
            ...$payload, 'expected_ticket_version' => 1,
        ])->assertConflict()->assertJsonPath('errors.command_key.0', 'COMMAND_KEY_REUSED');

        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('work_order_scope_items', 1);
        $this->assertDatabaseCount('operation_history', 1);
        $this->assertSame(1, $ticket->fresh()->lock_version);
    }

    /** TW07 */
    #[DataProvider('ineligibleTicketStates')]
    public function test_ineligible_ticket_state_returns_409_without_creating_work(string $status): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $ticket = $this->approvedTicket($site, ['status' => $status]);

        $this->actingAs($coordinator)->postJson(route('work-orders.m1.store'), $this->payload($site, $ticket))
            ->assertConflict()->assertJsonPath('errors.ticket_id.0', 'TICKET_NOT_AUTHORIZED');

        $this->assertSame(0, $ticket->fresh()->lock_version);
        $this->assertNoWorkOrderEffects();
    }

    public static function ineligibleTicketStates(): array
    {
        return [
            'new' => ['new'], 'triaged' => ['triaged'],
            'awaiting authorization' => ['awaiting_authorization'],
            'resolved' => ['resolved'], 'rejected' => ['rejected'],
            'cancelled' => ['cancelled'], 'duplicate' => ['duplicate'],
        ];
    }

    /** TW08, TW13 */
    public function test_manual_installation_without_assets_or_schedule_has_explicit_origin_and_required_scope(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $payload = [...$this->payload($site), 'service_type' => 'install'];

        $this->actingAs($coordinator)->post(route('work-orders.m1.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');

        $workOrder = WorkOrder::query()->sole();
        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id, 'origin_kind' => 'manual', 'ticket_id' => null,
            'manual_reason' => 'Direct operational request', 'scheduled_at' => null,
            'acceptance_context_ref' => 'manual:'.$workOrder->wo_number,
        ]);
        $this->assertDatabaseCount('service_tickets', 0);
        $this->assertDatabaseCount('work_order_assets', 0);
        $this->assertDatabaseCount('work_order_scope_items', 1);
        $this->assertDatabaseCount('operation_history', 1);
    }

    /** TW09, TW10 */
    #[DataProvider('invalidOrigins')]
    public function test_invalid_origin_combination_returns_422_without_side_effects(array $changes, string $field): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $payload = [...$this->payload($site), ...$changes];

        $this->actingAs($coordinator)->postJson(route('work-orders.m1.store'), $payload)
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertNoWorkOrderEffects();
    }

    public static function invalidOrigins(): array
    {
        return [
            'missing reason' => [['origin_kind' => 'manual', 'manual_reason' => null], 'manual_reason'],
            'empty reason' => [['origin_kind' => 'manual', 'manual_reason' => ''], 'manual_reason'],
            'whitespace reason' => [['origin_kind' => 'manual', 'manual_reason' => " \t "], 'manual_reason'],
            'reason type' => [['origin_kind' => 'manual', 'manual_reason' => ['invalid']], 'manual_reason'],
            'ticket without ticket id' => [['origin_kind' => 'ticket', 'manual_reason' => null], 'ticket_id'],
            'legacy_unknown writer' => [['origin_kind' => 'legacy_unknown'], 'origin_kind'],
            'manual ticket version' => [['origin_kind' => 'manual', 'expected_ticket_version' => 1], 'expected_ticket_version'],
            'missing scope' => [['origin_kind' => 'manual', 'initial_scope' => null], 'initial_scope'],
            'scope description type' => [['origin_kind' => 'manual', 'initial_scope' => [
                'description' => ['invalid'], 'acceptance_criteria' => 'Live feed works',
            ]], 'initial_scope.description'],
        ];
    }

    public function test_manual_origin_with_a_ticket_id_returns_422(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $ticket = $this->approvedTicket($site);

        $this->actingAs($coordinator)->postJson(route('work-orders.m1.store'), [
            ...$this->payload($site), 'ticket_id' => $ticket->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('ticket_id');

        $this->assertNoWorkOrderEffects();
    }

    public function test_ticket_origin_rejects_manual_reason_and_stale_version_with_409(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $ticket = $this->approvedTicket($site, ['lock_version' => 2]);
        $payload = $this->payload($site, $ticket);

        $this->actingAs($coordinator)->postJson(route('work-orders.m1.store'), [
            ...$payload, 'expected_ticket_version' => 1,
        ])->assertConflict()->assertJsonPath('errors.expected_ticket_version.0', 'TICKET_VERSION_CONFLICT');

        $this->assertNoWorkOrderEffects();
        $this->postJson(route('work-orders.m1.store'), [...$payload, 'manual_reason' => 'Unexpected reason'])
            ->assertUnprocessable()->assertJsonValidationErrors('manual_reason');
        $this->assertNoWorkOrderEffects();
    }

    /** TW11 */
    public function test_cross_site_ticket_is_rejected_even_for_the_same_customer(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $otherSite = $site->customer->sites()->create(['site_name' => 'Warehouse', 'address' => 'Bekasi']);
        $ticket = $this->approvedTicket($site);

        $this->actingAs($coordinator)->post(route('work-orders.m1.store'), $this->payload($otherSite, $ticket))
            ->assertSessionHasErrors('customer_site_id');

        $this->assertNoWorkOrderEffects();
        $this->assertSame('approved', $ticket->fresh()->status);
        $this->assertSame(0, $ticket->fresh()->lock_version);
    }

    /** TW12 */
    public function test_asset_site_is_rechecked_under_lock_after_initial_validation(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $otherSite = $this->site();
        $asset = $this->asset($site);
        $changed = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($asset, $otherSite, &$changed): void {
            if (! $changed && str_contains($sql, 'from `assets`') && str_contains($sql, 'for update')) {
                $changed = true;
                DB::table('assets')->where('id', $asset->id)->update(['customer_site_id' => $otherSite->id]);
            }
        });

        $this->actingAs($coordinator)->post(route('work-orders.m1.store'), [
            ...$this->payload($site), 'asset_ids' => [$asset->id],
        ])->assertSessionHasErrors('asset_ids');

        $this->assertTrue($changed);
        $this->assertNoWorkOrderEffects();
    }

    public function test_ticket_asset_deleted_between_validation_and_lock_returns_a_field_error(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $asset = $this->asset($site);
        $ticket = ServiceTicket::factory()->create();
        $deleted = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($asset, &$deleted): void {
            if (! $deleted && str_contains($sql, 'from `assets`') && str_contains($sql, 'for update')) {
                $deleted = true;
                DB::table('assets')->where('id', $asset->id)->delete();
            }
        });

        $this->actingAs($coordinator)->post(route('tickets.triage', $ticket), [
            'command_key' => (string) Str::uuid(), 'expected_ticket_version' => 0,
            'site_id' => $site->id, 'primary_asset_id' => $asset->id, 'coverage_disposition' => 'covered',
        ])->assertSessionHasErrors('primary_asset_id');

        $this->assertTrue($deleted);
        $this->assertSame('new', $ticket->fresh()->status);
        $this->assertDatabaseCount('operation_history', 0);
    }

    /** TW12 */
    public function test_ticket_primary_asset_must_belong_to_the_ticket_site(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $otherSite = $this->site();
        $asset = $this->asset($otherSite);
        $ticket = $this->approvedTicket($site, ['primary_asset_id' => $asset->id]);

        $this->actingAs($coordinator)->post(route('work-orders.m1.store'), $this->payload($site, $ticket))
            ->assertSessionHasErrors('ticket_id');

        $this->assertNoWorkOrderEffects();
        $this->assertSame(0, $ticket->fresh()->lock_version);
    }

    public function test_ticket_customer_is_rechecked_after_a_site_changes(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $otherSite = $this->site();
        $ticket = $this->approvedTicket($site);
        $changed = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($site, $otherSite, &$changed): void {
            if (! $changed && str_contains($sql, 'from `customer_sites`') && str_contains($sql, 'lock in share mode')) {
                $changed = true;
                DB::table('customer_sites')->where('id', $site->id)->update(['customer_id' => $otherSite->customer_id]);
            }
        });

        $this->actingAs($coordinator)->post(route('work-orders.m1.store'), $this->payload($site, $ticket))
            ->assertSessionHasErrors('customer_site_id');

        $this->assertTrue($changed);
        $this->assertNoWorkOrderEffects();
    }

    #[DataProvider('m1TextFields')]
    public function test_m1_text_fields_reject_invalid_utf8(string $field): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $payload = $this->payload($site);
        data_set($payload, $field, "\xFF");

        $this->actingAs($coordinator)->post(route('work-orders.m1.store'), $payload)->assertSessionHasErrors($field);

        $this->assertNoWorkOrderEffects();
    }

    public static function m1TextFields(): array
    {
        return [
            'manual reason' => ['manual_reason'],
            'scope' => ['initial_scope.description'],
            'criteria' => ['initial_scope.acceptance_criteria'],
        ];
    }

    /** TW15 */
    public function test_listing_legacy_work_order_does_not_fabricate_origin_scope_or_history(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $workOrder = WorkOrder::query()->create([
            'wo_number' => 'WO-LEGACY', 'customer_site_id' => $site->id, 'created_by' => $coordinator->id,
            'title' => 'Historical job', 'description' => 'Historical record',
            'service_type' => 'maintenance', 'category' => 'cctv', 'priority' => 'medium',
        ]);

        $this->actingAs($coordinator)->get(route('work-orders.index'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('workOrders.data.0.id', $workOrder->id)->etc());

        $this->assertNull($workOrder->fresh()->origin_kind);
        $this->assertDatabaseCount('work_order_scope_items', 0);
        $this->assertDatabaseCount('operation_history', 0);
    }

    /** TW16, TW17 */
    #[DataProvider('failurePoints')]
    public function test_failure_rolls_back_all_effects_and_the_same_key_can_be_retried(string $sqlPrefix): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $ticket = $this->approvedTicket($site);
        $asset = $this->asset($site);
        $payload = [...$this->payload($site, $ticket), 'asset_ids' => [$asset->id]];
        $failed = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($sqlPrefix, &$failed): void {
            if (! $failed && str_starts_with($sql, $sqlPrefix)) {
                $failed = true;
                throw new RuntimeException('Simulated M1 write failure.');
            }
        });
        Exceptions::fake();

        $this->actingAs($coordinator)->post(route('work-orders.m1.store'), $payload)->assertInternalServerError();

        Exceptions::assertReported(RuntimeException::class);
        $this->assertTrue($failed);
        $this->assertNoWorkOrderEffects();
        $this->assertDatabaseCount('operation_history', 0);
        $this->assertSame('approved', $ticket->fresh()->status);
        $this->assertSame(0, $ticket->fresh()->lock_version);
        $this->post(route('work-orders.m1.store'), $payload)
            ->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');
        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('work_order_assets', 1);
        $this->assertDatabaseCount('work_order_scope_items', 1);
        $this->assertDatabaseCount('operation_history', 1);
        $this->assertSame(1, $ticket->fresh()->lock_version);
    }

    public static function failurePoints(): array
    {
        return [
            'WO and origin' => ['insert into `work_orders`'],
            'asset pivot' => ['insert into `work_order_assets`'],
            'initial scope' => ['insert into `work_order_scope_items`'],
            'ticket update' => ['update `service_tickets`'],
            'command history' => ['insert into `operation_history`'],
        ];
    }

    /** TW19 */
    public function test_coordinator_gets_ticket_prefill_and_the_m1_store_route(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $ticket = $this->approvedTicket($site);

        $this->actingAs($coordinator)->get(route('work-orders.create', ['ticket_id' => $ticket->id]))
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('work-orders/create')->where('m1.ticket_id', $ticket->id)
            ->where('m1.ticket_version', 0)->where('m1.site_id', $site->id)
            ->where('links.store', route('work-orders.m1.store'))->etc());
    }

    /** TW20 */
    #[DataProvider('forbiddenRoles')]
    public function test_other_roles_cannot_open_or_post_m1_creation(?string $role): void
    {
        $user = User::factory()->create(['role' => $role]);
        $site = $this->site();

        $this->actingAs($user)->get(route('work-orders.create', ['m1' => 1]))->assertForbidden();
        $this->post(route('work-orders.m1.store'), $this->payload($site))->assertForbidden();

        $this->assertNoWorkOrderEffects();
    }

    public static function forbiddenRoles(): array
    {
        return ['admin' => ['admin'], 'technician' => ['teknisi'], 'missing' => [null], 'unsupported' => ['finance']];
    }

    public function test_technician_cannot_read_ticket_list_or_detail(): void
    {
        $technician = User::factory()->create(['role' => 'teknisi']);
        $ticket = ServiceTicket::factory()->create();

        $this->actingAs($technician)->get(route('tickets.index'))->assertForbidden();
        $this->get(route('tickets.show', $ticket))->assertForbidden();
    }

    public function test_admin_cannot_triage_or_approve_a_ticket(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $ticket = ServiceTicket::factory()->create(['created_by' => $admin->id]);

        $this->actingAs($admin)->post(route('tickets.triage', $ticket), [])->assertForbidden();
        $this->post(route('tickets.approve', $ticket), [])->assertForbidden();

        $this->assertSame('new', $ticket->fresh()->status);
        $this->assertDatabaseCount('operation_history', 0);
    }

    public function test_coordinator_cannot_use_admin_intake_endpoints(): void
    {
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('tickets.create'))->assertForbidden();
        $this->post(route('tickets.store'), [])->assertForbidden();

        $this->assertDatabaseCount('service_tickets', 0);
        $this->assertDatabaseCount('operation_history', 0);
    }

    /** TW21 */
    public function test_guest_is_redirected_from_m1_form_and_store(): void
    {
        $this->get(route('work-orders.create', ['m1' => 1]))->assertRedirectToRoute('login');
        $this->post(route('work-orders.m1.store'), [])->assertRedirectToRoute('login');

        $this->assertNoWorkOrderEffects();
    }

    /** TW22 */
    #[DataProvider('ineligibleAccounts')]
    public function test_ineligible_account_is_logged_out_before_m1_creation(array $attributes): void
    {
        $user = User::factory()->create(['role' => 'koordinator', ...$attributes]);

        $this->actingAs($user)->post(route('work-orders.m1.store'), [])->assertRedirectToRoute('login');

        $this->assertGuest();
        $this->assertNoWorkOrderEffects();
    }

    public static function ineligibleAccounts(): array
    {
        return [
            'inactive' => [['is_active' => false]],
            'pending' => [['approval_status' => 'pending']],
            'rejected' => [['approval_status' => 'rejected']],
        ];
    }

    /** TW23 */
    public function test_actor_revoked_during_the_transaction_is_rechecked_under_lock(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $changed = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($coordinator, &$changed): void {
            if (! $changed && str_contains($sql, 'from `users`') && str_contains($sql, 'for update')) {
                $changed = true;
                DB::table('users')->where('id', $coordinator->id)->update(['role' => 'teknisi']);
            }
        });

        $this->actingAs($coordinator)->post(route('work-orders.m1.store'), $this->payload($site))->assertForbidden();

        $this->assertTrue($changed);
        $this->assertNoWorkOrderEffects();
    }

    public function test_direct_service_call_rejects_a_stale_coordinator_model(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        DB::table('users')->where('id', $coordinator->id)->update(['is_active' => false]);

        try {
            app(CreateWorkOrderService::class)->create($coordinator, $this->payload($site));
            $this->fail('A revoked actor must be rejected.');
        } catch (AuthorizationException) {
            $this->assertNoWorkOrderEffects();
        }
    }

    /** TW24 */
    public function test_expand_accepts_the_legacy_payload_and_records_transition_provenance_only(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $legacy = collect($this->payload($site))->except([
            'command_key', 'origin_kind', 'ticket_id', 'expected_ticket_version', 'manual_reason', 'initial_scope',
        ])->all();

        $this->actingAs($coordinator)->post(route('work-orders.store'), $legacy)
            ->assertSessionHasNoErrors()->assertRedirectToRoute('work-orders.index');

        $this->assertDatabaseHas('work_orders', ['origin_kind' => null, 'ticket_id' => null, 'manual_reason' => null]);
        $this->assertDatabaseCount('work_order_scope_items', 0);
        $this->assertDatabaseCount('work_order_updates', 0);
        $history = OperationHistory::query()->sole();
        $this->assertSame('work_order.legacy_create', $history->action);
        $this->assertSame('C1', $history->facts['cohort']);
        $this->assertSame('expand_legacy', $history->facts['writer']);
    }

    /** TW25 */
    public function test_cutover_rejects_legacy_store_and_renders_the_m1_form(): void
    {
        config(['m1.writer_mode' => 'm1']);
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->post(route('work-orders.store'), [])
            ->assertSessionHasErrors('origin_kind');

        $this->assertNoWorkOrderEffects();
        $this->get(route('work-orders.create'))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('m1.ticket_id', null)->where('links.store', route('work-orders.m1.store'))->etc());
    }

    /** TW26 */
    public function test_replay_does_not_bypass_revoked_endpoint_permissions(): void
    {
        $coordinator = $this->coordinator();
        $site = $this->site();
        $payload = $this->payload($site);
        app(CreateWorkOrderService::class)->create($coordinator, $payload);
        DB::table('users')->where('id', $coordinator->id)->update(['role' => 'teknisi']);

        $this->actingAs($coordinator->fresh())->postJson(route('work-orders.m1.store'), $payload)->assertForbidden();

        $this->assertDatabaseCount('work_orders', 1);
        $this->assertDatabaseCount('work_order_scope_items', 1);
        $this->assertDatabaseCount('operation_history', 1);
    }

    private function coordinator(): User
    {
        return User::factory()->create(['role' => 'koordinator']);
    }

    private function site(): CustomerSite
    {
        $customer = Customer::query()->create(['name' => 'PT Testing']);

        return $customer->sites()->create(['site_name' => 'Office', 'address' => 'Bekasi']);
    }

    private function asset(CustomerSite $site): Asset
    {
        return $site->assets()->create([
            'asset_code' => 'ASSET-'.Str::ulid(), 'category' => 'cctv', 'status' => 'active',
        ]);
    }

    private function approvedTicket(CustomerSite $site, array $attributes = []): ServiceTicket
    {
        return ServiceTicket::factory()->create([
            'site_id' => $site->id, 'customer_id' => $site->customer_id,
            'status' => 'approved', 'coverage_disposition' => 'covered', ...$attributes,
        ]);
    }

    private function payload(CustomerSite $site, ?ServiceTicket $ticket = null): array
    {
        return [
            'command_key' => (string) Str::uuid(),
            'origin_kind' => $ticket ? 'ticket' : 'manual',
            'ticket_id' => $ticket?->id,
            'expected_ticket_version' => $ticket?->lock_version,
            'manual_reason' => $ticket ? null : 'Direct operational request',
            'initial_scope' => ['description' => 'Restore camera feed', 'acceptance_criteria' => 'Live feed works'],
            'customer_site_id' => $site->id, 'title' => 'Camera inspection', 'description' => 'Camera offline',
            'service_type' => 'maintenance', 'category' => 'cctv', 'priority' => 'high', 'asset_ids' => [],
        ];
    }

    private function assertNoWorkOrderEffects(): void
    {
        $this->assertDatabaseCount('work_orders', 0);
        $this->assertDatabaseCount('work_order_assets', 0);
        $this->assertDatabaseCount('work_order_scope_items', 0);
        $this->assertDatabaseCount('work_order_technicians', 0);
        $this->assertSame(0, OperationHistory::query()->where('action', 'work_order.create')->count());
    }
}
