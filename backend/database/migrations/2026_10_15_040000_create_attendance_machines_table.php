<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_machines', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 100);
            $table->string('serial_number', 100)->unique();
            $table->string('location');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_machines');
    }
};
