<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'nid_nip',
        'name',
        'position',
        'machine_id',
        'employment_start_date',
        'attendance_type',
        'attendance_type_start_date',
        'attendance_type_end_date',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'employment_start_date' => 'date:Y-m-d',
            'attendance_type_start_date' => 'date:Y-m-d',
            'attendance_type_end_date' => 'date:Y-m-d',
            'active' => 'boolean',
        ];
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function fingerprintScans(): HasMany
    {
        return $this->hasMany(FingerprintScan::class);
    }
}
