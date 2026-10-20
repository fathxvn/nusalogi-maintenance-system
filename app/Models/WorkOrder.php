<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

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
        return [
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
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

    public function technicians(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'work_order_technicians',
            'work_order_id',
            'user_id'
        )
            ->withPivot('assigned_at')
            ->withTimestamps();
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(
            CustomerSite::class,
            'customer_site_id'
        );
    }
}
