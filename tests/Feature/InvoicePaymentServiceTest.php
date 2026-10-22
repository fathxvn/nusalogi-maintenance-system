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

    
    
    public function test_payment_is_rejected_when_invoice_is_draft(): void
    {
        $user = User::factory()->create();

        $customer = Customer::create([
            'name' => 'PT Draft Testing',
        ]);

        $site = CustomerSite::create([
            'customer_id' => $customer->id,
            'site_name' => 'Kantor Bekasi',
            'address' => 'Bekasi',
        ]);

        $wo = WorkOrder::create([
            'wo_number' => 'WO-DRAFT-001',
            'customer_site_id' => $site->id,
            'created_by' => $user->id,
            'title' => 'Maintenance CCTV',
            'description' => 'Pemeriksaan perangkat CCTV.',
            'service_type' => 'maintenance',
            'category' => 'cctv',
        ]);

            $invoice = Invoice::create([
            'invoice_number' => 'INV-DRAFT-001',
            'work_order_id' => $wo->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'bill_to_name' => $customer->name,
        ]);

    $this->assertSame('draft', $invoice->fresh()->status);

        $service = app(InvoicePaymentService::class);

            try {
                $service->recordPayment(
                    $invoice->id,
                    $user,
                    '500000.00',
                    'transfer',
                    'TRF-DRAFT-001'
                );

                $this->fail('Pembayaran invoice draft seharusnya ditolak.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('invoice', $e->errors());
            }

            $this->assertSame(0, $invoice->payments()->count());
        }

    
    
    public function test_payment_rejects_zero_and_negative_amounts(): void
    {
        $user = User::factory()->create();

        $customer = Customer::create([
            'name' => 'PT Validasi Pembayaran',
        ]);

        $site = CustomerSite::create([
            'customer_id' => $customer->id,
            'site_name' => 'Site Testing',
            'address' => 'Bekasi',
        ]);

        $wo = WorkOrder::create([
            'wo_number' => 'WO-VALID-001',
            'customer_site_id' => $site->id,
            'created_by' => $user->id,
            'title' => 'Maintenance Jaringan',
            'description' => 'Pemeriksaan perangkat jaringan.',
            'service_type' => 'maintenance',
            'category' => 'networking',
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-VALID-001',
            'work_order_id' => $wo->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'bill_to_name' => $customer->name,
        ]);

        $invoice->forceFill([
            'status' => 'issued',
            'grand_total' => '1500000.00',
        ])->save();

        $service = app(InvoicePaymentService::class);

        foreach (['0.00', '-500000.00'] as $amount) {
            try {
                $service->recordPayment(
                    $invoice->id,
                    $user,
                    $amount,
                    'transfer'
                );

                $this->fail("Nominal {$amount} seharusnya ditolak.");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('amount', $e->errors());
            }
        }

        $this->assertSame(0, $invoice->payments()->count());
    }

    
    public function test_payment_is_rejected_after_invoice_is_fully_paid(): void
    {
        $user = User::factory()->create();

        $customer = Customer::create([
            'name' => 'PT Lunas Testing',
        ]);

        $site = CustomerSite::create([
            'customer_id' => $customer->id,
            'site_name' => 'Kantor Bekasi',
            'address' => 'Bekasi',
        ]);

        $wo = WorkOrder::create([
            'wo_number' => 'WO-PAID-001',
            'customer_site_id' => $site->id,
            'created_by' => $user->id,
            'title' => 'Maintenance CCTV',
            'description' => 'Perbaikan CCTV customer.',
            'service_type' => 'maintenance',
            'category' => 'cctv',
        ]);

        $invoice = Invoice::create([
            'invoice_number' => 'INV-PAID-001',
            'work_order_id' => $wo->id,
            'customer_id' => $customer->id,
            'created_by' => $user->id,
            'bill_to_name' => $customer->name,
        ]);

        $invoice->forceFill([
            'status' => 'issued',
            'grand_total' => '1500000.00',
        ])->save();

        $service = app(InvoicePaymentService::class);

        // Lunasi invoice.
        $service->recordPayment(
            $invoice->id,
            $user,
            '1500000.00',
            'transfer',
            'TRF-PAID-001'
        );

        $this->assertSame('paid', $invoice->paymentStatus());

        // Coba tambahkan pembayaran setelah lunas.
        try {
            $service->recordPayment(
                $invoice->id,
                $user,
                '100000.00',
                'transfer',
                'TRF-PAID-002'
            );

            $this->fail('Invoice lunas tidak boleh menerima pembayaran tambahan.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('amount', $e->errors());
        }

        $this->assertSame(1, $invoice->payments()->count());
        $this->assertSame('0.00', $invoice->remainingBalance());
    }



}
