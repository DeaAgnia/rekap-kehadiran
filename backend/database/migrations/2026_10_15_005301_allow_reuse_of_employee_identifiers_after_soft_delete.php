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
        Schema::table('employees', function (Blueprint $table) {
            // Soft-deleted rows keep their history, but no longer reserve identifiers.
            $table->dropUnique('employees_nid_nip_unique');
            $table->dropUnique('employees_machine_id_unique');
            $table->index('nid_nip');
            $table->index('machine_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex('employees_nid_nip_index');
            $table->dropIndex('employees_machine_id_index');
            $table->unique('nid_nip');
            $table->unique('machine_id');
        });
    }
};
