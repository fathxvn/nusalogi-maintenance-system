<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerSite extends Model
{
    protected $fillable = [
        'customer_id',
        'site_name',
        'address',
        'pic_name',
        'pic_phone',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
