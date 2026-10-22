<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FingerprintScan extends Model
{
    protected $fillable = [
        'employee_id',
        'scanned_at',
        'device_serial',
        'verify_code',
        'import_batch_id',
        'raw_payload',
    ];

    protected function casts(): array
    {
        return [
            'scanned_at' => 'datetime',
            'raw_payload' => 'array',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function attendanceMachine(): BelongsTo
    {
        return $this->belongsTo(AttendanceMachine::class, 'device_serial', 'serial_number');
    }
}
