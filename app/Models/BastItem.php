<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BastItem extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ordinal' => 'integer'];
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BastVersion::class, 'version_id');
    }

    public function reportRevision(): BelongsTo
    {
        return $this->belongsTo(ServiceReport::class, 'report_revision_id');
    }
}
