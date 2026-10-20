<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
