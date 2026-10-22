<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->date('attendance_date')->index();
            $table->string('status', 30);
            $table->string('manual_status', 30)->nullable();
            $table->time('check_in')->nullable();
            $table->time('check_out')->nullable();
            $table->string('source', 30)->default('manual');
            $table->boolean('incomplete')->default(false);
            $table->text('note')->nullable();
            $table->unsignedBigInteger('import_batch_id')->nullable()->index();
            $table->timestamps();

            $table->unique(['employee_id', 'attendance_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
