<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    protected $fillable = [
        'invoice_number',
        'work_order_id',
        'customer_id',
        'created_by',
        'bill_to_name',
        'bill_to_address',
        'issued_at',
        'due_date',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(InvoicePayment::class);
    }

    public function paidAmount(): string
    {
        $total = $this->payments()->sum('amount');

        return bcadd('0.00', (string) $total, 2);
    }

    public function remainingBalance(): string
    {
        return bcsub(
            $this->grand_total,
            $this->paidAmount(),
            2
        );
    }

    public function paymentStatus(): string
    {
        // invoice draft/void tidak memiliki status
        // pembayaran aktif
        if ($this->status !== 'issued') {
            return 'not_applicable';
        }

        $totalPaid = $this->paidAmount();

        if (bccomp($totalPaid, '0.00', 2) === 0) {
            return 'unpaid';
        }

        if (bccomp($totalPaid, $this->grand_total, 2) >= 0) {
            return 'paid';
        }

        return 'partial';
    }
}
