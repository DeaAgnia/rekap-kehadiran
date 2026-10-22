<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkSchedule extends Model
{
    protected $fillable = [
        'name',
        'work_start',
        'work_end',
        'break_start',
        'break_end',
        'late_tolerance_minutes',
        'early_leave_tolerance_minutes',
        'effective_from',
        'effective_until',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date:Y-m-d',
            'effective_until' => 'date:Y-m-d',
            'active' => 'boolean',
            'late_tolerance_minutes' => 'integer',
            'early_leave_tolerance_minutes' => 'integer',
        ];
    }
}
