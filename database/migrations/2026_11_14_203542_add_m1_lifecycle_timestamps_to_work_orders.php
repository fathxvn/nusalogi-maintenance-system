<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dateTime('started_at', 6)->nullable();
            $table->dateTime('field_finished_at', 6)->nullable();
            $table->dateTime('closed_at', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table): void {
            $table->dropColumn(['started_at', 'field_finished_at', 'closed_at']);
        });
    }
};
