<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ServiceReport extends Model
{
    protected $fillable = [
        'work_order_id',
        'created_by',
        'diagnosis',
        'action_taken',
        'result',
        'recommendation',
    ];

    protected function casts(): array
    {
        return [
            'scope_item_id' => 'integer',
            'revision_no' => 'integer',
            'previous_revision_id' => 'integer',
            'checklist_snapshot' => 'array',
            'usage_snapshot' => 'array',
            'asset_results' => 'array',
            'lock_version' => 'integer',
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function scopeItem(): BelongsTo
    {
        return $this->belongsTo(
            WorkOrderScopeItem::class,
            'scope_item_id',
        );
    }

    public function previousRevision(): BelongsTo
    {
        return $this->belongsTo(
            self::class,
            'previous_revision_id',
        );
    }

    public function nextRevision(): HasOne
    {
        return $this->hasOne(
            self::class,
            'previous_revision_id',
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Preserve the original relationship name for existing callers.
     */
    public function riviewer(): BelongsTo
    {
        return $this->reviewer();
    }

    public function photos(): HasMany
    {
        return $this->hasMany(ServicePhoto::class);
    }
}
