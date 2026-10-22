<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerSite;
use App\Models\Invoice;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\InvoicePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InvoicePaymentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_cannot_exceed_remaining_balance(): void
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
            'wo_number' => 'WO-PAY-001',
            'customer_site_id' => $site->id,
            'created_by' => $user->id,
            'title' => 'Maintenance CCTV',
            'description' => 'Perbaikan CCTV pelanggan.',
            'service_type' => 'maintenance',
            'category' => 'cctv',
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-PAY-001',
            'work_order_id' => $wo->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'bill_to_name' => $customer->name,
        ]);

        // Menyiapkan invoice issued khusus testing.
        $invoice->forceFill([
            'status' => 'issued',
            'grand_total' => '1500000.00',
        ])->save();

        $service = app(InvoicePaymentService::class);

        // Pembayaran pertama berhasil.
        $service->recordPayment(
            $invoice->id,
            $user,
            '500000.00',
            'transfer',
            'TRF-AUTO-001'
        );

        // Pembayaran kedua melebihi sisa tagihan.
        try {
            $service->recordPayment(
                $invoice->id,
                $user,
                '1200000.00',
                'transfer',
                'TRF-AUTO-OVER'
            );

            $this->fail('Pembayaran berlebih seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        // Pastikan pembayaran gagal tidak tersimpan.
        $this->assertSame(1, $invoice->payments()->count());

        $this->assertSame('500000.00', $invoice->paidAmount());

        $this->assertSame('1000000.00', $invoice->remainingBalance());
    }
}
