<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendanceMachine extends Model
{
    protected $fillable = [
        'name',
        'serial_number',
        'location',
        'active',
    ];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
