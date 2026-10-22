<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->string('attachment_path')->nullable()->after('note');
            $table->string('attachment_name')->nullable()->after('attachment_path');
            $table->string('attachment_mime_type', 100)->nullable()->after('attachment_name');
            $table->unsignedBigInteger('attachment_size')->nullable()->after('attachment_mime_type');
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table): void {
            $table->dropColumn([
                'attachment_path',
                'attachment_name',
                'attachment_mime_type',
                'attachment_size',
            ]);
        });
    }
};
