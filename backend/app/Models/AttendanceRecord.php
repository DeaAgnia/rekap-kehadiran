<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceRecord extends Model
{
    protected $fillable = [
        'employee_id',
        'attendance_date',
        'status',
        'manual_status',
        'check_in',
        'check_out',
        'source',
        'incomplete',
        'note',
        'attachment_path',
        'attachment_name',
        'attachment_mime_type',
        'attachment_size',
        'import_batch_id',
    ];

    protected $hidden = [
        'attachment_path',
        'attachment_name',
        'attachment_mime_type',
        'attachment_size',
    ];

    protected function casts(): array
    {
        return [
            'attendance_date' => 'date:Y-m-d',
            'incomplete' => 'boolean',
            'attachment_size' => 'integer',
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
}
