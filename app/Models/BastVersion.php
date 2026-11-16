<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BastVersion extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'lock_version' => 'integer', 'scope_snapshot' => 'array', 'issued_at' => 'datetime', 'decided_at' => 'datetime', 'recorded_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(BastDocument::class, 'document_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(BastItem::class, 'version_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function issuedFile(): BelongsTo
    {
        return $this->belongsTo(PrivateEvidenceFile::class, 'issued_file_id');
    }

    public function decisionFile(): BelongsTo
    {
        return $this->belongsTo(PrivateEvidenceFile::class, 'decision_file_id');
    }
}
