<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ImportBatch extends Model
{
    protected $fillable = [
        'filename',
        'row_count',
        'imported_count',
        'skipped_count',
        'error_count',
        'status',
        'errors',
        'imported_by',
    ];

    protected function casts(): array
    {
        return [
            'row_count' => 'integer',
            'imported_count' => 'integer',
            'skipped_count' => 'integer',
            'error_count' => 'integer',
            'errors' => 'array',
        ];
    }

    public function scans(): HasMany
    {
        return $this->hasMany(FingerprintScan::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }
}
