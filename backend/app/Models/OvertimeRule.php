<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OvertimeRule extends Model
{
    protected $fillable = [
        'name',
        'starts_after_minutes',
        'max_minutes',
        'effective_from',
        'effective_until',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'starts_after_minutes' => 'integer',
            'max_minutes' => 'integer',
            'effective_from' => 'date:Y-m-d',
            'effective_until' => 'date:Y-m-d',
            'active' => 'boolean',
        ];
    }
}
