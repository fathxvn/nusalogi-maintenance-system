<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends Model
{
    protected $fillable = [
        'name',
        'pic_name',
        'phone',
        'email',
        'billing_address',
    ];

    public function sites(): HasMany
    {
        return $this->hasMany(CustomerSite::class);
    }
}
