<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('attendance_type', 20)->default('normal')->after('employment_start_date');
            $table->date('attendance_type_start_date')->nullable()->after('attendance_type');
            $table->date('attendance_type_end_date')->nullable()->after('attendance_type_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropColumn(['attendance_type', 'attendance_type_start_date', 'attendance_type_end_date']);
        });
    }
};
