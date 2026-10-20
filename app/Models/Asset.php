<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Asset extends Model
{
    protected $fillable = [
        'customer_site_id',
        'asset_code',
        'category',
        'brand',
        'model',
        'serial_number',
        'status',
        'installed_at',
    ];

    protected function casts(): array
    {
        return [
            'installed_at' => 'date',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(
            CustomerSite::class,
            'customer_site_id'
        );
    }

    public function workOrders(): BelongsToMany
    {
        return $this->belongsToMany(
            WorkOrder::class,
            'work_order_assets',
            'asset_id',
            'work_order_id'
        );
    }
    
}
