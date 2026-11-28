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
            // MySQL: VIRTUAL generated column. Postgres: STORED generated column.
            $column = $table->dateTime('active_starts_at')->nullable();
            $expression = "(CASE WHEN status = 'cancelled' THEN NULL ELSE starts_at END)";

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $column->storedAs($expression);
            } else {
                $column->virtualAs($expression);
            }

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
