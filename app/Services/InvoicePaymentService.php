<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoicePaymentService
{
    /**
     * Create a new class instance.
     */
    public function __construct()
    {
        //
    }

    public function recordPayment(
        int $invoiceId,
        User $actor,
        string $amount,
        string $paymentMethod,
        ?string $referenceNumber = null
    ): InvoicePayment {
        return db::transaction(function () use (
            $invoiceId,
            $actor,
            $amount,
            $paymentMethod,
            $referenceNumber
        ) {
            $invoice = Invoice::query()
            ->lockForUpdate()
            ->findOrFail($invoiceId);

            // Invoice harus sudah diterbitkan.
            if ($invoice->status !== 'issued') {
                throw ValidationException::withMessages([
                    'invoice' => 'Invoice belum diterbitkan.',
                ]);
            }

            // gunakan string decimal, bukan float
            if (
                !preg_match('/^\d+(?:\.\d{1,2})?$/D', $amount) ||
                bccomp($amount, '0', 2) <= 0
            ) {
                throw ValidationException::withMessages([
                    'amount' => 'Nomimal pembayaran harus positif.',
                ]);
            }

            if (!in_array($paymentMethod, ['cash', 'transfer'], true)) {
                throw ValidationException::withMessages([
                    'payment_method' => 'Metode pembayaran tidak valid.',
                ]);
            }

            $totalPaid = $invoice->payments()->sum('amount');

            $remaining = bcsub(
                $invoice->grand_total,
                $totalPaid,
                2
            );

            if (bccomp($amount, $remaining, 2) > 0) {
                throw ValidationException::withMessages([
                    'amount' => 'Pembayaran melibihi sisa tagihan.',
                ]);
            }

            return $invoice->payments()->create([
                'recorded_by' => $actor->id,
                'amount' => $amount,
                'paid_at' => now(),
                'payment_method' => $paymentMethod,
                'reference_number' => $referenceNumber,
            ]);
        });
    }
}
