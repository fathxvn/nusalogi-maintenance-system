<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationHistory extends Model
{
    protected $table = 'operation_history';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'facts' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
