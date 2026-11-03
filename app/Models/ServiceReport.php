<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',

        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
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
