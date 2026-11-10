<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_visits', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('work_order_id')
                ->constrained('work_orders')
                ->restrictOnDelete();

            $table->unsignedInteger('visit_no');
            $table->dateTime('starts_at', 6);
            $table->dateTime('ends_at', 6);
            $table->string('zone_name', 64);
            $table->string('status', 20)->default('draft');

            $table->foreignId('previous_visit_id')
                ->nullable()
                ->constrained('work_visits')
                ->restrictOnDelete();

            $table->text('reason')->nullable();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->dateTime('created_at', 6);
            $table->unsignedInteger('lock_version')->default(0);

            $table->unique(['work_order_id', 'visit_no']);
            $table->unique('previous_visit_id');
            $table->index(['work_order_id', 'status', 'starts_at']);
        });

        DB::statement(
            'ALTER TABLE work_visits
                ADD CONSTRAINT work_visits_interval_check
                    CHECK (ends_at > starts_at),
                ADD CONSTRAINT work_visits_number_check
                    CHECK (visit_no > 0)'
        );

        Schema::create('visit_bookings', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('visit_id')
                ->constrained('work_visits')
                ->restrictOnDelete();

            $table->foreignId('technician_id')
                ->constrained('users')
                ->restrictOnDelete();

            $table->string('status', 20)->default('confirmed');

            $table->foreignId('confirmed_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->dateTime('confirmed_at', 6);
            $table->dateTime('actual_start_at', 6)->nullable();
            $table->dateTime('actual_end_at', 6)->nullable();
            $table->text('ended_reason')->nullable();

            $table->unique(['visit_id', 'technician_id']);
            $table->index(['technician_id', 'status', 'visit_id']);
        });

        DB::statement(
            'ALTER TABLE visit_bookings
                ADD CONSTRAINT visit_bookings_actual_time_check
                    CHECK (
                        actual_end_at IS NULL
                        OR (
                            actual_start_at IS NOT NULL
                            AND actual_end_at >= actual_start_at
                        )
                    )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('visit_bookings');
        Schema::dropIfExists('work_visits');
    }
};
