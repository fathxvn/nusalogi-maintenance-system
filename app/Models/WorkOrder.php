<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrder extends Model
{
    protected $fillable = [
        'wo_number',
        'customer_site_id',
        'created_by',
        'title',
        'description',
        'service_type',
        'category',
        'priority',
        'scheduled_at',
    ];

    protected function casts(): array
    {
        return $this->belongsTo(
            CustomerSite::class,
            'customer_site_id'
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'created_by'
        );
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'verified_by'
        );
    }
}
