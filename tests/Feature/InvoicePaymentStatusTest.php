<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InvoicePaymentStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_payment_status_changes_based_on_payments(): void
    {
        $user = User::factory()->create();

        $customer = Customer::create([
            'name' => 'PT Testing Otomatis',
        ]);

        $site = CustomerSite::create([
            'customer_id' => $customer->id,
            'site_name' => 'Kantor Bekasi',
            'address' => 'Bekasi',
        ]);

        $wo = WorkOrder::create([
            'wo_number' => 'WO-AUTO-001',
            'customer_site_id' => $site->id,
            'created_by' => $user->id,
            'title' => 'Maintenance CCTV',
            'description' => 'Pemeriksaan dan perbaikan CCTV yang tidak menampilkan gambar.',
            'service_type' => 'maintenance',
            'category' => 'cctv',
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-AUTO-001',
            'work_order_id' => $wo->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'bill_to_name' => $customer->name,
        ]);

        // Simulasi invoice terbit khusus pengujian status.
        $invoice->forceFill([
            'status' => 'issued',
            'grand_total' => '1500000.00',
        ])->save();

        // Kondisi 1: Belum dibayar
        $this->assertSame('unpaid', $invoice->paymentStatus());

        // Kondisi 2: Pembayaran sebagian
        $invoice->payments()->create([
            'recorded_by' => $user->id,
            'amount' => '500000.00',
            'paid_at' => now(),
            'payment_method' => 'transfer',
        ]);

        $this->assertSame('partial', $invoice->paymentStatus());
        $this->assertSame('1000000.00', $invoice->remainingBalance());

        // Kondisi 3: Lunas
        $invoice->payments()->create([
            'recorded_by' => $user->id,
            'amount' => '1000000.00',
            'paid_at' => now(),
            'payment_method' => 'transfer',
        ]);

        $this->assertSame('paid', $invoice->paymentStatus());
        $this->assertSame('0.00', $invoice->remainingBalance());
    }
}
