<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainRelationshipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_retrieves_its_sites(): void
    {
        $site = $this->createSite();
        $this->createSite();

        $sites = $site->customer->sites;

        $this->assertSame([$site->id], $sites->modelKeys());
    }

    public function test_site_retrieves_its_work_orders(): void
    {
        $workOrder = $this->createWorkOrder();
        $this->createWorkOrder();

        $workOrders = $workOrder->site->workOrders;

        $this->assertSame([$workOrder->id], $workOrders->modelKeys());
    }

    public function test_site_retrieves_its_assets(): void
    {
        $site = $this->createSite();
        $asset = $site->assets()->create([
            'asset_code' => 'ASSET-001',
            'category' => 'cctv',
            'installed_at' => '2026-10-20',
        ]);
        $this->createSite()->assets()->create([
            'asset_code' => 'ASSET-002',
            'category' => 'networking',
        ]);

        $assets = $site->assets;

        $this->assertSame([$asset->id], $assets->modelKeys());
        $this->assertTrue($assets->first()->site->is($site));
        $this->assertSame('2026-10-20', $assets->first()->installed_at->toDateString());
    }

    public function test_work_order_retrieves_assigned_technicians_with_pivot_data(): void
    {
        $workOrder = $this->createWorkOrder();
        $technician = User::factory()->create(['role' => 'teknisi']);
        $assignedAt = '2026-10-20 09:30:00';
        $workOrder->technicians()->attach($technician, ['assigned_at' => $assignedAt]);

        $technicians = $workOrder->technicians;

        $this->assertSame([$technician->id], $technicians->modelKeys());
        $this->assertSame($assignedAt, $technicians->first()->pivot->assigned_at);
        $this->assertNotNull($technicians->first()->pivot->created_at);
        $this->assertNotNull($technicians->first()->pivot->updated_at);
        $this->assertSame([$workOrder->id], $technician->assignedWorkOrders->modelKeys());
    }

    public function test_work_order_retrieves_assets_through_its_pivot_table(): void
    {
        $workOrder = $this->createWorkOrder();
        $asset = $workOrder->site->assets()->create([
            'asset_code' => 'ASSET-001',
            'category' => 'cctv',
        ]);
        $workOrder->assets()->attach($asset);

        $assets = $workOrder->assets;

        $this->assertSame([$asset->id], $assets->modelKeys());
        $this->assertSame([$workOrder->id], $asset->workOrders->modelKeys());
    }

    public function test_work_order_retrieves_reports_and_their_photos(): void
    {
        $workOrder = $this->createWorkOrder();
        $report = $workOrder->reports()->create(['created_by' => $workOrder->created_by]);
        $photo = $report->photos()->create([
            'uploaded_by' => $workOrder->created_by,
            'phase' => 'before',
            'storage_path' => 'reports/before.jpg',
        ]);

        $reports = $workOrder->reports;

        $this->assertSame([$report->id], $reports->modelKeys());
        $this->assertTrue($reports->first()->workOrder->is($workOrder));
        $this->assertTrue($reports->first()->creator->is($workOrder->creator));
        $this->assertSame([$photo->id], $reports->first()->photos->modelKeys());
        $this->assertTrue($photo->serviceReport->is($report));
        $this->assertTrue($photo->uploader->is($workOrder->creator));
    }

    public function test_service_report_retrieves_its_reviewer_and_casts_review_dates(): void
    {
        $workOrder = $this->createWorkOrder();
        $reviewer = User::factory()->create();
        $report = $workOrder->reports()->create(['created_by' => $workOrder->created_by]);
        $report->forceFill([
            'reviewed_by' => $reviewer->id,
            'submitted_at' => '2026-10-20 09:00:00',
            'reviewed_at' => '2026-10-20 10:00:00',
        ])->save();

        $report = $report->fresh();

        $this->assertSame('2026-10-20 10:00:00', $report->reviewed_at->toDateTimeString());
        $this->assertSame('2026-10-20 09:00:00', $report->submitted_at->toDateTimeString());
        $this->assertTrue($report->reviewer->is($reviewer));
        $this->assertTrue($report->riviewer->is($reviewer));
    }

    public function test_work_order_retrieves_invoices_and_their_items_and_payments(): void
    {
        $workOrder = $this->createWorkOrder();
        $invoice = $workOrder->invoices()->create([
            'invoice_number' => 'INV-001',
            'customer_id' => $workOrder->site->customer_id,
            'created_by' => $workOrder->created_by,
            'bill_to_name' => 'Test Customer',
        ]);
        $item = $invoice->items()->create([
            'item_type' => 'service',
            'description' => 'Maintenance',
            'quantity' => '1.00',
            'unit' => 'visit',
            'unit_price' => '100.00',
            'line_total' => '100.00',
        ]);
        $payment = $invoice->payments()->create([
            'recorded_by' => $workOrder->created_by,
            'amount' => '100.00',
            'paid_at' => '2026-10-20 12:00:00',
            'payment_method' => 'transfer',
        ]);

        $invoices = $workOrder->invoices;

        $this->assertSame([$invoice->id], $invoices->modelKeys());
        $this->assertTrue($invoice->workOrder->is($workOrder));
        $this->assertTrue($invoice->customer->is($workOrder->site->customer));
        $this->assertTrue($invoice->creator->is($workOrder->creator));
        $this->assertSame([$item->id], $invoice->items->modelKeys());
        $this->assertTrue($item->invoice->is($invoice));
        $this->assertSame([$payment->id], $invoice->payments->modelKeys());
        $this->assertTrue($payment->invoice->is($invoice));
        $this->assertTrue($payment->recordedBy->is($workOrder->creator));
    }

    public function test_work_order_retrieves_its_status_updates_and_verifier(): void
    {
        $workOrder = $this->createWorkOrder();
        $verifier = User::factory()->create();
        $workOrder->forceFill(['verified_by' => $verifier->id])->save();
        $update = $workOrder->updates()->create([
            'changed_by' => $verifier->id,
            'from_status' => 'pending',
            'to_status' => 'scheduled',
        ]);

        $updates = $workOrder->updates;

        $this->assertSame([$update->id], $updates->modelKeys());
        $this->assertTrue($updates->first()->workOrder->is($workOrder));
        $this->assertTrue($updates->first()->changedBy->is($verifier));
        $this->assertTrue($workOrder->verifier->is($verifier));
    }

    public function test_site_cannot_be_deleted_while_it_has_work_orders(): void
    {
        $workOrder = $this->createWorkOrder();

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23000');

        $workOrder->site->delete();
    }

    public function test_creator_cannot_be_deleted_while_their_work_order_exists(): void
    {
        $workOrder = $this->createWorkOrder();

        $this->expectException(QueryException::class);
        $this->expectExceptionCode('23000');

        $workOrder->creator->delete();
    }

    private function createSite(): CustomerSite
    {
        $customer = Customer::create(['name' => 'Test Customer']);

        return $customer->sites()->create([
            'site_name' => 'Test Site',
            'address' => 'Bekasi',
        ]);
    }

    private function createWorkOrder(): WorkOrder
    {
        $site = $this->createSite();
        $creator = User::factory()->create();

        return WorkOrder::create([
            'wo_number' => 'WO-'.$site->id,
            'customer_site_id' => $site->id,
            'created_by' => $creator->id,
            'title' => 'Maintenance',
            'description' => 'Inspect equipment.',
            'service_type' => 'maintenance',
            'category' => 'cctv',
        ]);
    }
}
