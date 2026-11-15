<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // NULL when cancelled so the same starts_at can be booked again after cancel.
            $table->dateTime('active_starts_at')
                ->nullable()
                ->virtualAs("(CASE WHEN status = 'cancelled' THEN NULL ELSE starts_at END)");

            $table->unique(['service_id', 'active_starts_at']);
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropUnique(['service_id', 'active_starts_at']);
            $table->dropColumn('active_starts_at');
        });
    }
};
