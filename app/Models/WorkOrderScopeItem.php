<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderScopeItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scope_no' => 'integer',
            'is_required' => 'boolean',
            'created_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(
            ServiceReport::class,
            'scope_item_id',
        );
    }
}
