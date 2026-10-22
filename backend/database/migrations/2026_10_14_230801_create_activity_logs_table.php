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
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->string('action', 80)->index();
            $table->string('entity_type', 120)->nullable()->index();
            $table->unsignedBigInteger('entity_id')->nullable()->index();
            $table->string('status', 20)->index();
            $table->text('description');
            $table->json('metadata')->nullable();
            $table->string('actor')->default('admin.sdm');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
