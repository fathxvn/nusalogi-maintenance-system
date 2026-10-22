<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceCalculationService
{
    public function recalculate(int $invoiceId): Invoice
    {
        return DB::transaction(function () use ($invoiceId) {

            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            // Untuk saat ini, hanya invoice draft
            // yang boleh dihitung ulang.
            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages([
                    'invoice' => 'Hanya invoice draft yang boleh diubah.',
                ]);
            }

            $items = $invoice->items()
                ->lockForUpdate()
                ->get();

            $subtotal = '0.00';

            foreach ($items as $item) {

                if (
                    bccomp($item->quantity, '0', 2) <= 0 ||
                    bccomp($item->unit_price, '0', 2) < 0
                ) {
                    throw ValidationException::withMessages([
                        'items' => 'Quantity harus positif dan harga tidak boleh negatif.',
                    ]);
                }

                // Hitung ulang, jangan percaya line_total
                // yang sebelumnya disimpan.
                $lineTotal = bcround(
                    bcmul($item->quantity, $item->unit_price, 4),
                    2
                );

                $item->line_total = $lineTotal;
                $item->save();

                $subtotal = bcadd($subtotal, $lineTotal, 2);
            }

            $discount = $invoice->discount_amount;
            $tax = $invoice->tax_amount;

            if (
                bccomp($discount, '0', 2) < 0 ||
                bccomp($tax, '0', 2) < 0 ||
                bccomp($discount, $subtotal, 2) > 0
            ) {
                throw ValidationException::withMessages([
                    'totals' => 'Diskon atau pajak tidak valid.',
                ]);
            }

            $grandTotal = bcadd(
                bcsub($subtotal, $discount, 2),
                $tax,
                2
            );

            $invoice->subtotal = $subtotal;
            $invoice->grand_total = $grandTotal;
            $invoice->save();

            return $invoice->refresh();
        });
    }
}
