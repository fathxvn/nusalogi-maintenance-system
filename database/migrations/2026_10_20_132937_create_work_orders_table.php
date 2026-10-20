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
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            
            $table->string('wo_number', 50)->unique();

            $table->foreignId('customer_site_id')
                ->constrained('customer_sites')
                ->restricOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restricOnDelete();

            $table->foreignId('verified_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('title', 150);
            $table->text('description');

            $table->string('service_type', 30);
            $table->string('category', 50);

            $table->string('priority', 20)
                ->default('medium');

            $table->string('status', 30)
                ->default('pending');

            $table->dateTime('scheduled_at')->nullable();
            $table->dateTime('completed_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'scheduled_at']); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};
