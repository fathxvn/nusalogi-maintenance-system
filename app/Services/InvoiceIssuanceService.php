<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceIssuanceService
{
    public function issue(int $invoiceId, User $actor): Invoice
    {
        return DB::transaction(function () use ($invoiceId, $actor) {

            $invoice = Invoice::query()
                ->lockForUpdate()
                ->findOrFail($invoiceId);

            // Hanya pembuat invoice yang boleh menerbitkan
            // untuk sementara, sebelum role diterapkan.
            if ((int) $invoice->created_by !== (int) $actor->id) {
                throw new AuthorizationException(
                    'Pengguna tidak berhak menerbitkan invoice.'
                );
            }

            if ($invoice->status !== 'draft') {
                throw ValidationException::withMessages([
                    'status' => 'Hanya invoice draft yang boleh diterbitkan.',
                ]);
            }

            if (! $invoice->items()->exists()) {
                throw ValidationException::withMessages([
                    'items' => 'Invoice harus memiliki minimal satu item.',
                ]);
            }

            // Hitung ulang sebelum penerbitan.
            $calculation = app(InvoiceCalculationService::class);

            $invoice = $calculation->recalculate($invoice->id);

            if (bccomp($invoice->grand_total, '0', 2) <= 0) {
                throw ValidationException::withMessages([
                    'grand_total' => 'Total invoice harus lebih besar dari nol.',
                ]);
            }

            $invoice->status = 'issued';
            $invoice->issued_at = now()->toDateString();
            $invoice->save();

            return $invoice->refresh();
        });
    }
}
