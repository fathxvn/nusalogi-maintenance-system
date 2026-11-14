<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaterialUsage extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'recorded_at' => 'datetime'];
    }

    public function scopeItem(): BelongsTo
    {
        return $this->belongsTo(WorkOrderScopeItem::class, 'scope_item_id');
    }

    public function parentMovement(): BelongsTo
    {
        return $this->belongsTo(self::class, 'adjusts_usage_id');
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(self::class, 'adjusts_usage_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
